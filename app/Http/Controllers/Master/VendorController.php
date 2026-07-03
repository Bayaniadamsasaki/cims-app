<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreVendorRequest;
use App\Http\Requests\Master\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use App\Interface\VendorRepositoryInterface;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    protected $vendorRepository;

    public function __construct(VendorRepositoryInterface $vendorRepository)
    {
        $this->vendorRepository = $vendorRepository;
    }

    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return VendorResource::collection($this->vendorRepository->all());
        }

        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['search']);

        $vendors = $this->vendorRepository->paginate($perPage, $filters);
        return VendorResource::collection($vendors);
    }

    public function store(StoreVendorRequest $request)
    {
        $vendor = $this->vendorRepository->create($request->validated());
        return new VendorResource($vendor);
    }

    public function show(int $id)
    {
        $vendor = $this->vendorRepository->find($id);
        return new VendorResource($vendor);
    }

    public function update(UpdateVendorRequest $request, int $id)
    {
        $vendor = $this->vendorRepository->update($id, $request->validated());
        return new VendorResource($vendor);
    }

    public function destroy(int $id)
    {
        $this->vendorRepository->delete($id);
        return response()->json([
            'message' => 'Vendor deleted successfully'
        ]);
    }
}
