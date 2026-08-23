<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Interface\BuildingRepositoryInterface;
use App\Interface\VendorRepositoryInterface;
use App\Interface\DeviceCategoryRepositoryInterface;
use App\Interface\FloorRepositoryInterface;
use App\Interface\RoomRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MasterWebController extends Controller
{
    protected $buildingRepo;
    protected $vendorRepo;
    protected $categoryRepo;
    protected $floorRepo;
    protected $roomRepo;

    public function __construct(
        BuildingRepositoryInterface $buildingRepo,
        VendorRepositoryInterface $vendorRepo,
        DeviceCategoryRepositoryInterface $categoryRepo,
        FloorRepositoryInterface $floorRepo,
        RoomRepositoryInterface $roomRepo
    ) {
        $this->buildingRepo = $buildingRepo;
        $this->vendorRepo = $vendorRepo;
        $this->categoryRepo = $categoryRepo;
        $this->floorRepo = $floorRepo;
        $this->roomRepo = $roomRepo;
    }

    // Buildings CRUD
    public function buildingsIndex()
    {
        $buildings = \App\Models\Building::with(['floors.rooms'])->orderBy('id', 'desc')->paginate(100)->items();

        // Total lantai & ruangan dihitung dari data aktual, karena struktur gedung
        // dikelola lewat menu Master > Floors (Lantai) dan Rooms (Ruangan).
        foreach ($buildings as $building) {
            $building->floors_count = $building->floors->count();
            $building->rooms_count = $building->floors->sum(fn($floor) => $floor->rooms->count());
        }

        return Inertia::render('Master/Buildings', ['buildings' => $buildings]);
    }

    public function buildingsStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:buildings,code',
            'description' => 'nullable|string',
        ]);

        \DB::transaction(function() use ($data) {
            $building = $this->buildingRepo->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'floors_count' => 1,
                'rooms_count' => 0,
                'description' => $data['description'] ?? null,
            ]);

            // Struktur minimal: Lantai 1 + Ruang Server, sesuai invariant yang dijaga
            // command cims:ensure-server-rooms. Lantai & ruangan lain ditambahkan
            // lewat menu Master > Floors (Lantai) dan Rooms (Ruangan).
            $floor = \App\Models\Floor::create([
                'building_id' => $building->id,
                'name' => 'Lantai 1',
                'level' => 1,
                'description' => 'Generated automatically for ' . $building->name,
            ]);

            \App\Models\Room::firstOrCreate(
                [
                    'floor_id' => $floor->id,
                    'name' => 'Ruang Server',
                ],
                [
                    'code' => strtoupper($building->code) . '-F1-RS',
                    'description' => 'Ruang Server & Core Network ' . $building->name,
                ]
            );

            // Recalculate total rooms count for building
            $building->update([
                'rooms_count' => \App\Models\Room::whereHas('floor', fn($q) => $q->where('building_id', $building->id))->count()
            ]);
        });

        return redirect()->back()->with('success', 'Building created successfully.');
    }

    public function buildingsUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:buildings,code,' . $id,
            'description' => 'nullable|string',
        ]);
        $this->buildingRepo->update($id, $data);
        return redirect()->back()->with('success', 'Building updated successfully.');
    }

    public function buildingsDestroy(int $id)
    {
        $this->buildingRepo->delete($id);
        return redirect()->back()->with('success', 'Building deleted successfully.');
    }

    // Vendors CRUD
    public function vendorsIndex()
    {
        $vendors = $this->vendorRepo->paginate(100)->items();
        return Inertia::render('Master/Vendors', ['vendors' => $vendors]);
    }

    public function vendorsStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
        ]);
        $this->vendorRepo->create($data);
        return redirect()->back()->with('success', 'Vendor created successfully.');
    }

    public function vendorsUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
        ]);
        $this->vendorRepo->update($id, $data);
        return redirect()->back()->with('success', 'Vendor updated successfully.');
    }

    public function vendorsDestroy(int $id)
    {
        $this->vendorRepo->delete($id);
        return redirect()->back()->with('success', 'Vendor deleted successfully.');
    }

    // Device Categories CRUD
    public function categoriesIndex()
    {
        $categories = $this->categoryRepo->paginate(100)->items();
        return Inertia::render('Master/Categories', ['categories' => $categories]);
    }

    public function categoriesStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:device_categories,name',
            'description' => 'nullable|string',
        ]);
        $this->categoryRepo->create($data);
        return redirect()->back()->with('success', 'Category created successfully.');
    }

    public function categoriesUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:device_categories,name,' . $id,
            'description' => 'nullable|string',
        ]);
        $this->categoryRepo->update($id, $data);
        return redirect()->back()->with('success', 'Category updated successfully.');
    }

    public function categoriesDestroy(int $id)
    {
        $this->categoryRepo->delete($id);
        return redirect()->back()->with('success', 'Category deleted successfully.');
    }

    // Floors CRUD
    public function floorsIndex(Request $request)
    {
        $buildingId = $request->query('building_id');

        $query = \App\Models\Floor::with('building')->withCount('rooms');

        if ($buildingId) {
            $query->where('building_id', $buildingId);
        }

        $floors = $query->orderBy('building_id')->orderBy('level')->paginate(100)->items();

        return Inertia::render('Master/Floors', [
            'floors' => $floors,
            'buildings' => $this->buildingRepo->all(),
            'filters' => ['building_id' => $buildingId],
            // Level yang sudah terpakai per gedung (lintas filter), dipakai form
            // untuk menyarankan level berikutnya agar tidak menabrak yang ada.
            'usedLevels' => \App\Models\Floor::query()
                ->select('building_id', 'level')
                ->get()
                ->groupBy('building_id')
                ->map(fn($rows) => $rows->pluck('level')->map(fn($level) => (int) $level)->sort()->values())
                ->toArray(),
        ]);
    }

    /**
     * Detail satu lantai beserta ruangan di dalamnya. Dari halaman ini ruangan
     * ditambahkan langsung ke lantai bersangkutan, tanpa dropdown lantai.
     */
    public function floorsShow(int $id)
    {
        $floor = \App\Models\Floor::with([
            'building',
            'rooms' => fn($q) => $q->orderBy('code')->orderBy('name'),
        ])->findOrFail($id);

        return Inertia::render('Master/FloorDetail', [
            'floor' => $floor,
        ]);
    }

    public function floorsStore(Request $request)
    {
        $data = $request->validate(
            $this->floorRules($request),
            $this->floorMessages()
        );

        $this->floorRepo->create($data);
        return redirect()->back()->with('success', 'Floor created successfully.');
    }

    public function floorsUpdate(Request $request, int $id)
    {
        $data = $request->validate(
            $this->floorRules($request, $id),
            $this->floorMessages()
        );

        $this->floorRepo->update($id, $data);
        return redirect()->back()->with('success', 'Floor updated successfully.');
    }

    public function floorsDestroy(int $id)
    {
        $this->floorRepo->delete($id);
        return redirect()->back()->with('success', 'Floor deleted successfully.');
    }

    /**
     * Lantai selalu terikat ke gedung, dan levelnya unik di dalam gedung itu —
     * aturan yang sama dijaga di database lewat UNIQUE(building_id, level).
     */
    private function floorRules(Request $request, ?int $ignoreId = null): array
    {
        $uniqueLevel = Rule::unique('floors', 'level')
            ->where(fn($q) => $q->where('building_id', $request->input('building_id')));

        if ($ignoreId !== null) {
            $uniqueLevel->ignore($ignoreId);
        }

        return [
            'building_id' => 'required|exists:buildings,id',
            'name' => 'required|string|max:255',
            'level' => ['required', 'integer', $uniqueLevel],
            'description' => 'nullable|string',
        ];
    }

    private function floorMessages(): array
    {
        return [
            'building_id.required' => 'Lantai harus ditempatkan di dalam sebuah gedung.',
            'level.unique' => 'Level lantai ini sudah terdaftar pada gedung yang dipilih.',
        ];
    }

    // Rooms CRUD
    public function roomsIndex(Request $request)
    {
        $buildingId = $request->query('building_id');
        $floorId = $request->query('floor_id');

        $query = \App\Models\Room::with(['floor.building']);

        if ($floorId) {
            $query->where('floor_id', $floorId);
        } elseif ($buildingId) {
            $query->whereHas('floor', fn($q) => $q->where('building_id', $buildingId));
        }

        $rooms = $query->orderBy('floor_id')->orderBy('code')->paginate(100)->items();

        return Inertia::render('Master/Rooms', [
            'rooms' => $rooms,
            // Dropdown bertingkat Building → Floor dibangun dari gedung beserta
            // lantainya, bukan dari daftar lantai yang berdiri sendiri.
            'buildings' => \App\Models\Building::with(['floors' => fn($q) => $q->orderBy('level')])
                ->orderBy('name')
                ->get(),
            'filters' => ['building_id' => $buildingId, 'floor_id' => $floorId],
        ]);
    }

    public function roomsStore(Request $request)
    {
        $data = $request->validate([
            'building_id' => 'nullable|exists:buildings,id',
            'floor_id' => 'required|exists:floors,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:rooms,code',
            'description' => 'nullable|string',
        ], $this->roomMessages());

        $this->assertFloorBelongsToBuilding($data['floor_id'], $data['building_id'] ?? null);

        $this->roomRepo->create(Arr::except($data, 'building_id'));
        return redirect()->back()->with('success', 'Room created successfully.');
    }

    public function roomsUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'building_id' => 'nullable|exists:buildings,id',
            'floor_id' => 'required|exists:floors,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:rooms,code,' . $id,
            'description' => 'nullable|string',
        ], $this->roomMessages());

        $this->assertFloorBelongsToBuilding($data['floor_id'], $data['building_id'] ?? null);

        $this->roomRepo->update($id, Arr::except($data, 'building_id'));
        return redirect()->back()->with('success', 'Room updated successfully.');
    }

    public function roomsDestroy(int $id)
    {
        $this->roomRepo->delete($id);
        return redirect()->back()->with('success', 'Room deleted successfully.');
    }

    private function roomMessages(): array
    {
        return [
            'floor_id.required' => 'Ruangan harus ditempatkan di dalam sebuah lantai.',
        ];
    }

    /**
     * `building_id` hanya dipakai dropdown bertingkat di sisi UI; nilainya tidak
     * ikut disimpan. Yang divalidasi di sini adalah rantainya tetap utuh —
     * lantai yang dipilih benar-benar milik gedung yang dipilih.
     */
    private function assertFloorBelongsToBuilding(int $floorId, $buildingId): void
    {
        if (empty($buildingId)) {
            return;
        }

        $isInsideBuilding = \App\Models\Floor::where('id', $floorId)
            ->where('building_id', $buildingId)
            ->exists();

        if (! $isInsideBuilding) {
            throw ValidationException::withMessages([
                'floor_id' => 'Lantai yang dipilih bukan bagian dari gedung tersebut.',
            ]);
        }
    }
}
