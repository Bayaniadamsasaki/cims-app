## Menu Sidebar: Laporan Speedtest Jaringan Bulanan

Buat sebuah menu **Sidebar → Laporan Speedtest Jaringan Bulanan** yang digunakan untuk mencatat, memantau, dan mendokumentasikan hasil pengujian kualitas jaringan secara berkala setiap bulan.

### 1. Struktur Data / Field

Tabel laporan harus memiliki field berikut:

| No | Field                  | Tipe Input         | Keterangan                                                                                            |
| -- | ---------------------- | ------------------ | ----------------------------------------------------------------------------------------------------- |
| 1  | **No**                 | Auto Number        | Nomor urut laporan, dibuat otomatis oleh sistem.                                                      |
| 2  | **Tanggal Action**     | Date & Time Picker | Tanggal dan waktu saat speedtest dilakukan.                                                           |
| 3  | **Lokasi**             | Text / Dropdown    | Lokasi tempat pengujian jaringan dilakukan.                                                           |
| 4  | **SSID**               | Text / Dropdown    | Nama jaringan Wi-Fi/SSID yang sedang diuji.                                                           |
| 5  | **Download (Mbps)**    | Number             | Kecepatan download hasil speedtest dalam Mbps.                                                        |
| 6  | **Upload (Mbps)**      | Number             | Kecepatan upload hasil speedtest dalam Mbps.                                                          |
| 7  | **Ping (ms)**          | Number             | Nilai latency/ping jaringan dalam milidetik.                                                          |
| 8  | **Status Action**      | Dropdown           | Status kondisi jaringan: **Lancar, Sedang, Lambat, Terputus**.                                        |
| 9  | **Perangkat Uji Coba** | Dropdown           | Perangkat yang digunakan untuk melakukan pengujian, contoh: **Laptop, HP/Smartphone**.                |
| 10 | **Penguji**            | Dropdown           | Nama petugas yang melakukan pengujian, contoh: **Rama, Adam**.                                        |
| 11 | **Tindakan / Action**  | Dropdown           | Tindakan yang dilakukan setelah hasil pengujian, yaitu: **Maintenance, Selesai, Monitoring Traffic**. |
| 12 | **Bukti Screenshot**   | File Upload        | Upload screenshot hasil speedtest sebagai bukti/dokumentasi pengujian.                                |

### 2. Dropdown

#### Status Action

Gunakan dropdown dengan pilihan:

* **Lancar**
* **Sedang**
* **Lambat**
* **Terputus**

Status sebaiknya ditampilkan menggunakan **Badge/Status Indicator** agar mudah dibaca.

Contoh:

* Lancar → status positif
* Sedang → status peringatan
* Lambat → status kritis
* Terputus → status error

#### Perangkat Uji Coba

Gunakan dropdown:

* **Laptop**
* **HP / Smartphone**
* **PC**
* **Tablet**
* **Lainnya**

#### Penguji

Gunakan dropdown:

* **Rama**
* **Adam**

Dropdown ini dibuat dinamis sehingga nama penguji dapat ditambahkan atau dikelola oleh administrator jika diperlukan.

#### Tindakan / Action

Gunakan dropdown:

* **Maintenance**
* **Selesai**
* **Monitoring Traffic**

### 3. Bukti Screenshot

Field **Bukti Screenshot** harus mendukung upload gambar hasil speedtest.

Format yang dapat diterima:

* JPG / JPEG
* PNG
* WEBP

Setelah file di-upload, tampilkan:

* Thumbnail/preview screenshot
* Nama file
* Tombol **Preview**
* Tombol **Download**
* Tombol **Hapus/Ganti**

Jika pengguna mengklik thumbnail, tampilkan gambar dalam **modal/lightbox** agar dapat dilihat dengan ukuran lebih besar.

### 4. Fitur Tabel

Tabel laporan harus menyediakan:

* **Search/Pencarian**
* **Filter berdasarkan tanggal**
* **Filter berdasarkan lokasi**
* **Filter berdasarkan SSID**
* **Filter berdasarkan Status Action**
* **Filter berdasarkan Penguji**
* **Filter berdasarkan Tindakan**
* **Sorting** berdasarkan tanggal, download, upload, dan ping
* **Pagination**
* Pilihan jumlah data per halaman: 10, 25, 50, 100
* **Export data** jika diperlukan

### 5. Action pada Setiap Data

Setiap baris laporan memiliki tombol/action:

* **Detail** → melihat seluruh informasi laporan
* **Edit** → mengubah data laporan
* **Hapus** → menghapus laporan dengan konfirmasi
* **Preview Screenshot** → melihat bukti screenshot

Gunakan confirmation dialog sebelum menghapus data.

### 6. Form Tambah Laporan

Sediakan tombol utama:

**+ Tambah Laporan Speedtest**

Ketika diklik, tampilkan form untuk mengisi:

1. Tanggal Action
2. Lokasi
3. SSID
4. Download (Mbps)
5. Upload (Mbps)
6. Ping (ms)
7. Status Action
8. Perangkat Uji Coba
9. Penguji
10. Tindakan / Action
11. Bukti Screenshot

Field **No** tidak perlu diisi manual karena dibuat otomatis oleh sistem.

Tambahkan validasi:

* Download, Upload, dan Ping hanya menerima angka.
* Semua field wajib diisi kecuali jika memang ditentukan sebagai opsional.
* Screenshot hanya menerima format gambar yang diperbolehkan.
* Tampilkan pesan error yang jelas apabila input tidak valid.

### 7. Dashboard Ringkasan

Pada bagian atas halaman, tambahkan beberapa **Summary Card** untuk memberikan gambaran kondisi jaringan bulanan:

* **Total Pengujian**
* **Lancar**
* **Sedang**
* **Lambat**
* **Terputus**
* **Rata-rata Download**
* **Rata-rata Upload**
* **Rata-rata Ping**

Tambahkan juga grafik jika diperlukan untuk mempermudah pemantauan tren kualitas jaringan, misalnya:

* Grafik rata-rata Download per tanggal
* Grafik rata-rata Upload per tanggal
* Grafik Ping per tanggal
* Distribusi status jaringan

### 8. Tujuan Menu

Menu ini ditujukan sebagai **sistem dokumentasi dan monitoring kualitas jaringan bulanan**, sehingga setiap pengujian speedtest memiliki:

**Data pengujian → Kondisi jaringan → Perangkat penguji → Petugas → Tindakan → Bukti Screenshot**

Pastikan desain halaman terlihat profesional, rapi, mudah digunakan, dan cocok untuk kebutuhan **monitoring jaringan/IT Support**.
