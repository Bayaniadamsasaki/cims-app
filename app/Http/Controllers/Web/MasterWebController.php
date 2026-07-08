<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Interface\BuildingRepositoryInterface;
use App\Interface\VendorRepositoryInterface;
use App\Interface\DeviceCategoryRepositoryInterface;
use App\Interface\FloorRepositoryInterface;
use App\Interface\RoomRepositoryInterface;
use App\Interface\RackRepositoryInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterWebController extends Controller
{
    protected $buildingRepo;
    protected $vendorRepo;
    protected $categoryRepo;
    protected $floorRepo;
    protected $roomRepo;
    protected $rackRepo;

    public function __construct(
        BuildingRepositoryInterface $buildingRepo,
        VendorRepositoryInterface $vendorRepo,
        DeviceCategoryRepositoryInterface $categoryRepo,
        FloorRepositoryInterface $floorRepo,
        RoomRepositoryInterface $roomRepo,
        RackRepositoryInterface $rackRepo
    ) {
        $this->buildingRepo = $buildingRepo;
        $this->vendorRepo = $vendorRepo;
        $this->categoryRepo = $categoryRepo;
        $this->floorRepo = $floorRepo;
        $this->roomRepo = $roomRepo;
        $this->rackRepo = $rackRepo;
    }

    // Buildings CRUD
    public function buildingsIndex()
    {
        $buildings = $this->buildingRepo->paginate(100)->items();
        return Inertia::render('Master/Buildings', ['buildings' => $buildings]);
    }

    public function buildingsStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:buildings,code',
            'description' => 'nullable|string',
        ]);
        $this->buildingRepo->create($data);
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
    public function floorsIndex()
    {
        $floors = $this->floorRepo->paginate(100)->items();
        $buildings = $this->buildingRepo->all();
        return Inertia::render('Master/Floors', [
            'floors' => $floors,
            'buildings' => $buildings
        ]);
    }

    public function floorsStore(Request $request)
    {
        $data = $request->validate([
            'building_id' => 'required|exists:buildings,id',
            'name' => 'required|string|max:255',
            'level' => 'required|integer',
            'description' => 'nullable|string',
        ]);
        $this->floorRepo->create($data);
        return redirect()->back()->with('success', 'Floor created successfully.');
    }

    public function floorsUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'building_id' => 'required|exists:buildings,id',
            'name' => 'required|string|max:255',
            'level' => 'required|integer',
            'description' => 'nullable|string',
        ]);
        $this->floorRepo->update($id, $data);
        return redirect()->back()->with('success', 'Floor updated successfully.');
    }

    public function floorsDestroy(int $id)
    {
        $this->floorRepo->delete($id);
        return redirect()->back()->with('success', 'Floor deleted successfully.');
    }

    // Rooms CRUD
    public function roomsIndex()
    {
        $rooms = $this->roomRepo->paginate(100)->items();
        $floors = $this->floorRepo->all();
        return Inertia::render('Master/Rooms', [
            'rooms' => $rooms,
            'floors' => $floors
        ]);
    }

    public function roomsStore(Request $request)
    {
        $data = $request->validate([
            'floor_id' => 'required|exists:floors,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:rooms,code',
            'description' => 'nullable|string',
        ]);
        $this->roomRepo->create($data);
        return redirect()->back()->with('success', 'Room created successfully.');
    }

    public function roomsUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'floor_id' => 'required|exists:floors,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:rooms,code,' . $id,
            'description' => 'nullable|string',
        ]);
        $this->roomRepo->update($id, $data);
        return redirect()->back()->with('success', 'Room updated successfully.');
    }

    public function roomsDestroy(int $id)
    {
        $this->roomRepo->delete($id);
        return redirect()->back()->with('success', 'Room deleted successfully.');
    }

    // Racks CRUD
    public function racksIndex()
    {
        $racks = $this->rackRepo->paginate(100)->items();
        $rooms = $this->roomRepo->all();
        return Inertia::render('Master/Racks', [
            'racks' => $racks,
            'rooms' => $rooms
        ]);
    }

    public function racksStore(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:racks,code',
            'height_u' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);
        $this->rackRepo->create($data);
        return redirect()->back()->with('success', 'Rack created successfully.');
    }

    public function racksUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:racks,code,' . $id,
            'height_u' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);
        $this->rackRepo->update($id, $data);
        return redirect()->back()->with('success', 'Rack updated successfully.');
    }

    public function racksDestroy(int $id)
    {
        $this->rackRepo->delete($id);
        return redirect()->back()->with('success', 'Rack deleted successfully.');
    }
}
