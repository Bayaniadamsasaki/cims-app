# CIMS UBG Dashboard Design System Specification

## 1. Design Vision & Concept
Dokumen ini mendefinisikan panduan UI/UX untuk **Campus Infrastructure Monitoring System (CIMS) UBG**. Desain ini mengadaptasi tata letak bergaya analitik modern yang bersih dan berbasis *card* (seperti referensi desain finansial), namun disesuaikan untuk kebutuhan *monitoring* infrastruktur jaringan.

**Perubahan Utama dari Referensi:** Tema warna hijau telah diganti menjadi **Biru (Blue)** sebagai identitas utama untuk memberikan kesan teknis, profesional, dan tepercaya.

## 2. Core Principles
*   **Clean & Airy:** Penggunaan *whitespace* (ruang kosong) yang luas dan pemisahan antar *card* yang jelas.
*   **Subtle Depth:** Desain cenderung *flat* namun menggunakan *drop shadow* yang sangat lembut (soft diffused) pada *card* untuk memisahkan konten dari *background*.
*   **Rounded & Friendly:** Penggunaan sudut melengkung yang konsisten (`rounded-xl` atau `rounded-2xl`) pada kontainer, tombol, dan form input.

## 3. Color Palette (Tailwind CSS Reference)
Sistem ini menggunakan *utility classes* (seperti Tailwind CSS) untuk mempercepat integrasi dengan komponen React.

*   **Backgrounds:**
    *   App Background (Main): `bg-slate-50` atau `bg-gray-50` (#F8FAFC) - Memberikan kontras lembut terhadap card.
    *   Card / Sidebar Background: `bg-white` (#FFFFFF)
*   **Primary Theme (Blue):**
    *   Main Buttons & Active Elements: `bg-blue-600` (#2563EB)
    *   Hover States: `bg-blue-700` (#1D4ED8)
    *   Active Sidebar Item / Soft Highlights: `bg-blue-50` (#EFF6FF) dengan teks `text-blue-700` (#1D4ED8).
*   **Typography:**
    *   Headings & Primary Values: `text-slate-900` (#0F172A)
    *   Secondary Text (Labels, Subtitles, Table Headers): `text-slate-500` (#64748B)
*   **Status & Alerts (Krusial untuk CIMS):**
    *   Online / Normal: `text-emerald-600 bg-emerald-50` (Warna hijau tetap dipertahankan *hanya* untuk indikator status positif/sukses).
    *   Offline / Critical / Danger: `text-red-600 bg-red-50`
    *   Warning / Maintenance: `text-amber-600 bg-amber-50`

## 4. Typography Rules
*   **Font Family:** 'Inter', 'Plus Jakarta Sans', atau modern sans-serif lainnya.
*   **Weights:**
    *   Regular (400): Untuk teks paragraf, *notes*, dan *table data*.
    *   Medium (500): Untuk label menu, nama kolom tabel, dan *secondary buttons*.
    *   Semibold (600) / Bold (700): Untuk angka metrik utama (misal: jumlah *device*), judul *card*, dan *headers*.

## 5. Layout Structure

### A. Global Layout
*   Menggunakan layout *Sidebar* di kiri dan *Main Content* di kanan.
*   Wrapper utama: `flex h-screen bg-slate-50 overflow-hidden`.

### B. Sidebar (Kiri)
*   *Background* putih (`bg-white`), tanpa border kanan yang tebal (gunakan `border-r border-slate-100` yang sangat tipis).
*   **Menu Items:** Ikon di kiri, Teks di kanan. Teks menggunakan warna abu-abu `text-slate-500`.
*   **Active State:** Saat menu aktif (misal: Dashboard), *background* item berubah menjadi biru muda (`bg-blue-50`), teks menjadi `text-blue-700`, dan ikon berwarna biru. Ujung item melengkung (`rounded-lg`).
*   **Menu CIMS:** Dashboard, Inventory, Monitoring, Alerts, Maintenance, Reports.

### C. Top Header (Navigasi Atas)
*   *Flexbox* rata tengah (`flex items-center justify-between`).
*   **Kiri:** *Search bar* global dengan *background* abu-abu muda (`bg-slate-100`), ujung sangat melengkung (`rounded-full`), dan ikon *search* di dalam input.
*   **Kanan:** Indikator Tanggal/Waktu real-time, tombol *Action* (misal: "Export Report" berwarna hitam/biru gelap melengkung), ikon Notifikasi (lonceng), dan *Profile Picture* admin.

### D. Main Content Area
*   Area yang bisa di-*scroll*.
*   *Padding* luas: `p-6` atau `p-8`.
*   Header Halaman: Teks sapaan besar (misal: "Welcome back, Network Admin") beserta subteks untuk memberikan ringkasan status hari ini.

## 6. UI Components & Widgets

### A. Metric Cards (Summary)
*   **Styling:** `bg-white rounded-2xl p-6 border border-slate-100 shadow-sm`.
*   **Layout:**
    *   Atas: Judul (misal: "Total Devices") dengan ikon di sebelah kiri. Ikon dibungkus dalam lingkaran berwarna lembut.
    *   Tengah: Angka metrik yang sangat besar dan tebal (misal: `text-3xl font-bold text-slate-900`).
    *   Bawah: Indikator tren kecil (misal: *Badge* hijau bertuliskan "+2 New" atau *badge* merah muda "-1 Offline").

### B. Main Chart (Overview)
*   Area *chart* lebar (mengambil 2 kolom pada *grid*).
*   *Bar Chart* atau *Area/Line Chart* untuk menampilkan **Network Traffic** atau **CPU/Memory Usage**.
*   Warna *bar/line* menggunakan variasi warna **Biru**. *Bar* pada *chart* harus memiliki sudut atas yang melengkung.

### C. Data Lists / Tables (Widget Kecil)
*   Digunakan untuk **Recent Alerts** atau **Active Maintenance**.
*   Tidak ada *border* vertikal pada tabel.
*   Pemisah antar baris menggunakan *border* bawah horizontal yang tipis (`border-b border-slate-50`).
*   Menggunakan *Status Badge*: Titik warna + Teks (contoh: `[Red Dot] Offline`).

## 7. Prompt untuk Claude CLI / AI Coder
Jika file ini digunakan sebagai referensi untuk men-generate kode (misalnya menggunakan Vite, React, dan Tailwind CSS), berikan instruksi berikut kepada AI:

> "Buatkan komponen halaman Dashboard menggunakan React dan Tailwind CSS berdasarkan file `design.md` ini. Terapkan struktur Sidebar, Top Header, dan Grid Layout. Buatlah *mock data* untuk CIMS (Campus Infrastructure Monitoring) yang mencakup:
> 1. 3 Metric Cards (Total Devices, Active Alerts, Maintenance Scheduled).
> 2. 1 Bar Chart area untuk 'Network Traffic Overview' (gunakan placeholder yang estetis jika library chart belum ada).
> 3. 2 List Widgets untuk 'Device Status' dan 'Recent Alerts'.
> Pastikan warna dominan adalah variasi Biru (`blue-600`, `blue-50`), *background* aplikasi `slate-50`, *card* berwarna putih dengan ujung melengkung `rounded-2xl`, dan beri *shadow* yang sangat lembut."
