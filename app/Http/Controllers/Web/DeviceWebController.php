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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\DevicesImport;
use App\Imports\SingleDeviceAuditImport;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Services\MikrotikService;

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

        if (array_key_exists('password', $data)) {
            if (!empty($data['password'])) {
                $data['password_encrypted'] = encrypt($data['password']);
            }
            unset($data['password']);
        }

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

        if (array_key_exists('password', $data)) {
            if (!empty($data['password'])) {
                $data['password_encrypted'] = encrypt($data['password']);
            }
            unset($data['password']);
        }

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

    /**
     * Import Excel Inventaris UBG menggunakan Maatwebsite\Excel.
     * Supports two formats:
     *   1. Single-device audit (sheets: Ringkasan Perangkat, Interface, IP Address)
     *   2. Master UBG inventory (sheets: GEDUNG & RUANGAN, Router)
     */
    public function import(Request $request)
    {
        Log::info("DeviceWebController::import - Entered");
        
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $file = $request->file('file');
        $tempPath = $file->storeAs('imports', 'inventaris_' . time() . '.' . $file->getClientOriginalExtension(), 'local');
        $fullPath = storage_path('app/' . $tempPath);
        
        Log::info("DeviceWebController::import - Saved file to " . $fullPath);

        // 1. Detect single-device audit format (Ringkasan Perangkat + Interface sheets)
        $canHandle = SingleDeviceAuditImport::canHandle($fullPath);
        Log::info("DeviceWebController::import - SingleDeviceAuditImport::canHandle: " . ($canHandle ? "YES" : "NO"));
        
        if ($canHandle) {
            $importer = new SingleDeviceAuditImport();
            $device = $importer->import($fullPath);

            if ($device) {
                Log::info("DeviceWebController::import - Successfully imported single device: " . $device->name);
                return back()->with('success', "Perangkat '{$device->name}' beserta " .
                    $device->deviceInterfaces()->count() . " port/interface berhasil diimport dari file audit Excel!");
            }

            Log::error("DeviceWebController::import - Failed to import single device");
            return back()->with('error', 'Gagal mengimport data dari file audit Excel. Periksa format file.');
        }

        Log::info("DeviceWebController::import - Falling back to UBG master import format");
        $importSuccess = false;

        // 2. Fallback: Master UBG inventory format (GEDUNG & RUANGAN + Router sheets)
        try {
            Artisan::call('import:ubg-excel', [
                'file' => $fullPath
            ]);
            Log::info("DeviceWebController::import - Fallback import:ubg-excel Artisan command completed");
            $importSuccess = true;
        } catch (\Throwable $e) {
            Log::error("DeviceWebController::import - Fallback import:ubg-excel failed: " . $e->getMessage());
        }

        // 3. If Artisan fallback failed, try direct Excel import
        if (!$importSuccess) {
            try {
                Excel::import(new DevicesImport, $fullPath);
                Log::info("DeviceWebController::import - Fallback Excel::import completed");
                $importSuccess = true;
            } catch (\Throwable $e2) {
                Log::error("DeviceWebController::import - Fallback Excel::import failed: " . $e2->getMessage());
            }
        }

        if ($importSuccess) {
            return back()->with('success', 'Data inventaris & port interface berhasil diimport dari file Excel!');
        }

        Log::error("DeviceWebController::import - All import methods failed");
        return back()->with('error', 'Gagal mengimport data dari file Excel. Periksa format file dan cek log untuk detail.');
    }

    public function uploadExcelView(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $path = $request->file('file')->store('excels', 'public');
        $url = asset('storage/' . $path);

        return back()->with('excel_path', $path)->with('excel_url', $url)->with('success', 'File Excel berhasil diupload. Anda dapat melihat dan mengeditnya di bawah.');
    }

    public function syncInterfaces(Request $request, int $id, MikrotikService $mikrotikService)
    {
        $device = Device::findOrFail($id);

        if ($device->ip_address) {
            try {
                $count = $mikrotikService->syncDeviceInterfaces($device);
                return back()->with('success', "Berhasil sinkronisasi {$count} interface dari router {$device->ip_address}!");
            } catch (\Throwable $e) {
                $count = $this->generateDefaultInterfaces($device);
                return back()->with('warning', "Live API ke {$device->ip_address} belum merespon. Dibuatkan {$count} port interface default.");
            }
        }

        $count = $this->generateDefaultInterfaces($device);
        return back()->with('success', "Berhasil membuat {$count} port interface default.");
    }

    private function generateDefaultInterfaces($device): int
    {
        $ports = ['ether1', 'ether2', 'ether3', 'ether4', 'ether5'];
        $created = 0;
        foreach ($ports as $idx => $portName) {
            DeviceInterface::firstOrCreate(
                [
                    'device_id' => $device->id,
                    'interface_name' => $portName,
                ],
                [
                    'mac_address' => $device->mac_address ? substr($device->mac_address, 0, 14) . sprintf('%02X', $idx + 1) : null,
                    'ip_address' => ($idx === 0) ? $device->ip_address : null,
                    'subnet' => ($idx === 0 && $device->ip_address) ? '/24' : null,
                    'interface_type' => 'Ethernet',
                    'interface_status' => ($idx === 0) ? 'up' : 'down',
                    'description' => ($idx === 0) ? 'WAN / Port Utama' : 'LAN Port ' . ($idx + 1),
                ]
            );
            $created++;
        }
        return $created;
    }
}
