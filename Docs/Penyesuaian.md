Tolong bantu saya menerapkan pemisahan data 'live_api' dan 'inventory' untuk aplikasi CIMS UBG saya, serta perbaiki bug UI di frontend. Kamu sudah memiliki izin FileEdit dan FileWrite, jadi silakan modifikasi file-file berikut secara langsung:

Migration: Buat file migration baru untuk menambahkan kolom source (tipe string, default: 'inventory') setelah kolom status pada tabel devices. Setelah file dibuat, jalankan perintah php artisan migrate.

Model: Edit app/Models/Device.php untuk menambahkan source ke dalam properti $fillable, dan pastikan password dimasukkan ke dalam properti $hidden untuk keamanan kredensial.

Controller: Edit app/Http/Controllers/DeviceController.php (pada method yang menangani form simpan/update). Konversi input checkbox boolean is_monitored menjadi string ('live_api' jika true, 'inventory' jika false) ke dalam array validasi sebagai source. Ingat untuk melakukan unset pada key is_monitored sebelum query create() atau update() dieksekusi.

Query Filtering: Edit app/Http/Controllers/Web/MonitoringWebController.php (pada method index()) dan app/Services/MonitoringService.php (pada method scanAll()). Tambahkan filter ->where('source', 'live_api') pada query pemanggilan model Device.

Frontend (React): Edit file resources/js/Pages/Devices/Index.jsx:

Tambahkan state is_monitored: false ke dalam inisialisasi useForm.

Sisipkan elemen input checkbox UI yang mengontrol nilai is_monitored ke dalam form "Tambah Perangkat", posisikan berdekatan dengan input Status Operasional.

Perbaiki bug badge warna status di bagian Modal Detail (sekitar baris 782). Ubah elemen yang di-hardcode (berteks 'Normal' dengan warna hijau) menjadi render kondisional dinamis menggunakan fungsi statusMap: di mana 'active' = Normal/hijau, 'maintenance' = Perawatan/kuning, dan 'offline' = Mati/merah.