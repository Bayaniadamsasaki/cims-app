<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Interface\BuildingRepositoryInterface;
use App\Interface\VendorRepositoryInterface;
use App\Interface\DeviceCategoryRepositoryInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasterWebController extends Controller
{
    protected $buildingRepo;
    protected $vendorRepo;
    protected $categoryRepo;

    public function __construct(
        BuildingRepositoryInterface $buildingRepo,
        VendorRepositoryInterface $vendorRepo,
        DeviceCategoryRepositoryInterface $categoryRepo
    ) {
        $this->buildingRepo = $buildingRepo;
        $this->vendorRepo = $vendorRepo;
        $this->categoryRepo = $categoryRepo;
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
}
