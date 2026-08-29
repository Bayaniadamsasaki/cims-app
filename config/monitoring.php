<?php

/**
 * Eksekusi monitoring: satu perangkat = satu job antrean.
 *
 * Nilai di sini mengatur perilaku antrean, bukan cara mengukur. Logika
 * pengukuran tetap sepenuhnya milik MonitoringService dan tidak boleh
 * menghasilkan angka karangan dalam kondisi apa pun.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Antrean khusus monitoring
    |--------------------------------------------------------------------------
    |
    | Pemindaian perangkat dipisahkan dari antrean default supaya ratusan job
    | pemindaian tidak menahan job lain (mis. notifikasi atau impor). Jumlah
    | koneksi jaringan paralel dikendalikan lewat jumlah worker yang melayani
    | antrean ini — aplikasi tidak pernah membuka koneksi paralel tanpa batas.
    |
    */

    'queue' => env('MONITORING_QUEUE', 'monitoring'),

    /*
    |--------------------------------------------------------------------------
    | Batas waktu satu job pemindaian (detik)
    |--------------------------------------------------------------------------
    |
    | HARUS lebih kecil dari `retry_after` koneksi antrean (config/queue.php,
    | default 90 detik). Kalau lebih besar, antrean akan melepas ulang job yang
    | sebenarnya masih berjalan sehingga satu perangkat dipindai dua kali.
    |
    */

    'job_timeout' => (int) env('MONITORING_JOB_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Percobaan ulang & jeda naik
    |--------------------------------------------------------------------------
    |
    | Gangguan sesaat (paket ICMP hilang, API router sedang sibuk) diberi
    | kesempatan ulang dengan jeda yang menaik, bukan langsung dianggap mati.
    | Kegagalan jaringan yang memang nyata tetap dicatat apa adanya oleh
    | MonitoringService pada percobaan pertama.
    |
    */

    'job_tries' => (int) env('MONITORING_JOB_TRIES', 3),

    'job_backoff' => [10, 30],

    /*
    |--------------------------------------------------------------------------
    | Umur kunci anti-duplikat per perangkat (detik)
    |--------------------------------------------------------------------------
    |
    | Selama kunci dipegang, dispatch berikutnya untuk perangkat yang sama
    | diabaikan. Nilainya harus menutup seluruh siklus percobaan ulang, tetapi
    | tetap kedaluwarsa sendiri supaya worker yang mati mendadak tidak
    | memblokir perangkat itu selamanya.
    |
    */

    'unique_for' => (int) env('MONITORING_UNIQUE_FOR', 300),

];
