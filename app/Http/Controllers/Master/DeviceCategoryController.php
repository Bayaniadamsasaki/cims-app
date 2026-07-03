<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreDeviceCategoryRequest;
use App\Http\Requests\Master\UpdateDeviceCategoryRequest;
use App\Http\Resources\DeviceCategoryResource;
use App\Interface\DeviceCategoryRepositoryInterface;
use Illuminate\Http\Request;

class DeviceCategoryController extends Controller
{
    protected $categoryRepository;

    public function __construct(DeviceCategoryRepositoryInterface $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return DeviceCategoryResource::collection($this->categoryRepository->all());
        }

        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['search']);

        $categories = $this->categoryRepository->paginate($perPage, $filters);
        return DeviceCategoryResource::collection($categories);
    }

    public function store(StoreDeviceCategoryRequest $request)
    {
        $category = $this->categoryRepository->create($request->validated());
        return new DeviceCategoryResource($category);
    }

    public function show(int $id)
    {
        $category = $this->categoryRepository->find($id);
        return new DeviceCategoryResource($category);
    }

    public function update(UpdateDeviceCategoryRequest $request, int $id)
    {
        $category = $this->categoryRepository->update($id, $request->validated());
        return new DeviceCategoryResource($category);
    }

    public function destroy(int $id)
    {
        $this->categoryRepository->delete($id);
        return response()->json([
            'message' => 'Device category deleted successfully'
        ]);
    }
}
