<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Interface\DeviceRepositoryInterface;
use App\Interface\BuildingRepositoryInterface;
use App\Interface\VendorRepositoryInterface;
use App\Interface\DeviceCategoryRepositoryInterface;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Rack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\DevicesImport;

class DeviceWebController extends Controller
{
    protected $deviceRepo;
    protected $buildingRepo;
    protected $vendorRepo;
    protected $categoryRepo;

    public function __construct(
        DeviceRepositoryInterface $deviceRepo,
        BuildingRepositoryInterface $buildingRepo,
        VendorRepositoryInterface $vendorRepo,
        DeviceCategoryRepositoryInterface $categoryRepo
    ) {
        $this->deviceRepo = $deviceRepo;
        $this->buildingRepo = $buildingRepo;
        $this->vendorRepo = $vendorRepo;
        $this->categoryRepo = $categoryRepo;
    }

    public function index(Request $request)
    {
        $filters = $request->only([
            'vendor_id', 'device_category_id', 'building_id', 'status', 'search'
        ]);

        $devices = $this->deviceRepo->paginate(100, $filters);
        
        $vendors = $this->vendorRepo->paginate(100)->items();
        $categories = $this->categoryRepo->paginate(100)->items();
        $buildings = $this->buildingRepo->paginate(100)->items();
        
        // Load all floors, rooms, racks for the dropdown linkage
        $floors = Floor::all();
        $rooms = Room::all();
        $racks = Rack::all();

        return Inertia::render('Devices/Index', [
            'devices' => $devices->items(),
            'vendors' => $vendors,
            'categories' => $categories,
            'buildings' => $buildings,
            'floors' => $floors,
            'rooms' => $rooms,
            'racks' => $racks,
            'filters' => $filters,
        ]);
    }

    public function store(StoreDeviceRequest $request)
    {
        $data = $request->validated();

        // Tangkap input checkbox is_monitored sebelum dihapus dari payload:
        //  - checked (true)  → source = live_api   (perangkat masuk Live Monitoring API)
        //  - unchecked (false) → source = inventory (perangkat hanya masuk inventaris statis)
        $isMonitored = filter_var(
            $request->input('is_monitored', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$request->has('source')) {
            $data['source'] = $isMonitored ? 'live_api' : 'inventory';
        }

        // Buang key is_monitored agar tidak ikut terkirim ke repository/create().
        unset($data['is_monitored']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('devices', 'public');
            $data['image_path'] = $path;
        }

        $this->deviceRepo->create($data);

        return redirect()->route('devices.index')->with('success', 'Device created successfully.');
    }

    public function update(UpdateDeviceRequest $request, int $id)
    {
        $data = $request->validated();
        $device = $this->deviceRepo->find($id);

        if ($request->hasFile('image')) {
            if ($device->image_path) {
                Storage::disk('public')->delete($device->image_path);
            }
            $path = $request->file('image')->store('devices', 'public');
            $data['image_path'] = $path;
        }

        $this->deviceRepo->update($id, $data);

        return redirect()->route('devices.index')->with('success', 'Device updated successfully.');
    }

    public function destroy(int $id)
    {
        $device = $this->deviceRepo->find($id);

        if ($device->image_path) {
            Storage::disk('public')->delete($device->image_path);
        }

        $this->deviceRepo->delete($id);

        return redirect()->route('devices.index')->with('success', 'Device deleted successfully.');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,max:10240'
        ]);

        $file = $request->file('file');
        $tempPath = $file->storeAs('imports', 'inventaris_' . time() . '.xlsx', 'local');
        $fullPath = storage_path('app/' . $tempPath);

        \Illuminate\Support\Facades\Artisan::call('import:ubg-excel', [
            'file' => $fullPath
        ]);

        return redirect()->route('devices.index')->with('success', 'Data inventaris Excel berhasil diimport ke sistem CIMS!');
    }

    /**
     * Import Excel Inventaris UBG menggunakan Maatwebsite\Excel.
     * Validasi file (xlsx/xls/csv, max 10 MB), lalu delegasikan ke DevicesImport.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        Excel::import(new DevicesImport, $request->file('file'));

        return back()->with('success', 'Data inventaris berhasil diimport dari file Excel!');
    }
}
