Tolong bantu saya menerapkan fitur "Import Excel" sekaligus pembaruan "Input Manual" untuk Device Inventory di aplikasi CIMS UBG. Kamu memiliki izin FileEdit dan FileWrite, jadi silakan langsung modifikasi file-file berikut:

1. Class Import (Maatwebsite Excel)
Jalankan perintah php artisan make:import DevicesImport --model=Device atau langsung buat/edit file app/Imports/DevicesImport.php.

Implementasikan ToCollection dan WithStartRow.

Di method startRow(), return nilai 4 karena data asli baru mulai di baris ke-4.

Di method collection(), lakukan loop dan gunakan Device::updateOrCreate(). Jadikan mac_address (index 12) sebagai acuan unik.

Petakan datanya: name (index 1), category_id (set ke 1 untuk router), vendor (index 4), model (index 5), firmware (index 7), serial_number (index 8), username (index 9), password (index 10), ip_address (index 14), status (index 18), dan set source statis menjadi 'inventory'. Jangan lupa tambahkan pengecekan if (!isset($row[1])) continue; untuk melewati baris kosong.

2. DeviceController
Edit app/Http/Controllers/DeviceController.php:

Tambahkan method import(Request $request) yang memvalidasi file (xlsx, xls, csv) lalu menjalankan Excel::import(new DevicesImport, $request->file('file')); dan me-return back() dengan pesan sukses.

Di method store() (input manual), pastikan input checkbox is_monitored ditangkap. Ubah menjadi live_api jika true, atau inventory jika false, lalu simpan ke array tervalidasi dengan key source. Hapus key is_monitored dengan unset() sebelum mengeksekusi Device::create().

3. Routes
Edit routes/web.php. Tambahkan route POST baru: Route::post('devices/import', [DeviceController::class, 'import'])->name('devices.import'); letakkan di dalam group middleware auth dan berdekatan dengan resource devices.

4. Frontend (Index.jsx)
Edit resources/js/Pages/Devices/Index.jsx:

Tambahkan form baru untuk Upload File Excel yang memanfaatkan useForm dari Inertiajs (dengan properti file: null). Form ini harus melakukan post ke route devices.import.

Pada form input manual yang sudah ada, pastikan state is_monitored: false dimasukkan ke useForm, dan tambahkan elemen UI checkbox untuk mengontrol state tersebut agar pengguna bisa memilih apakah perangkat manual ini masuk ke "Live Monitoring API" atau tidak.

Lakukan penulisan dan modifikasi kodenya secara langsung ke file sekarang.