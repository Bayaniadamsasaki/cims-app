<?php

namespace App\Services;

use App\Models\SpeedtestResult;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Speedtest yang diukur DARI ROUTER, bukan dari server aplikasi.
 *
 * SpeedtestService yang sudah ada mengukur jalur server Laravel → Cloudflare.
 * Angka itu sah, tapi bukan angka uplink kampus: server bisa duduk di belakang
 * router lain, di VM yang di-throttle, atau di jaringan yang sama sekali beda.
 * Container speedtest-cli di RouterOS mengukur dari router itu sendiri, jadi
 * yang keluar adalah kapasitas gateway yang sebenarnya.
 *
 * RouterOS tidak menyediakan "exec" lewat API. Container hanya bisa di-start,
 * dan keluarannya baru bisa dibaca kalau container itu logging=yes — stdout-nya
 * masuk ke /log. Alur di kelas ini mengikuti kenyataan itu: start → baca /log →
 * parse. Tidak ada jalan lain lewat API.
 *
 * Sengaja dua fase (start, lalu poll berulang) dan bukan satu request yang
 * menunggu: satu putaran butuh 30–90 detik, jauh di atas batas wajar satu
 * request PHP-FPM, dan pendekatan poll ini tidak bergantung pada queue worker
 * yang harus hidup.
 */
class MikrotikContainerSpeedtestService
{
    /** Penanda sumber untuk baris speedtest_results yang diukur dari router. */
    public const SOURCE = 'router-container';

    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    /**
     * Host tanpa bagian port — dipakai sebagai kunci cache dan disimpan sebagai
     * router_host, supaya "192.168.91.1" dan "192.168.91.1:7111" tidak dihitung
     * sebagai dua router yang berbeda.
     */
    protected function hostKey(?string $host): string
    {
        $raw = $host ?: (string) config('services.mikrotik.host');

        return str_contains($raw, ':') ? explode(':', $raw)[0] : $raw;
    }

    protected function cacheKey(?string $host): string
    {
        return 'mikrotik_speedtest_run:' . $this->hostKey($host);
    }

    /**
     * Container speedtest di router ini, atau null bila tidak ada yang cocok.
     *
     * @return array<string,mixed>|null
     *
     * @throws RuntimeException bila /container tidak bisa dibaca sama sekali.
     */
    public function findContainer(?string $host = null): ?array
    {
        try {
            $rows = $this->mikrotik->client($host)->query('/container/print')->read();
        } catch (\Throwable $e) {
            throw new RuntimeException('Tidak bisa membaca /container dari router: ' . $e->getMessage());
        }

        // RouterOS mengirim penolakan sebagai data biasa, bukan exception. Tanpa
        // pemeriksaan ini "paket container belum terpasang" akan terlihat sama
        // seperti "router ini tidak punya container speedtest".
        if (isset($rows['after']['message'])) {
            throw new RuntimeException(
                'Router tidak mengenali /container. Kemungkinan paket container belum terpasang, '
                . 'device-mode container belum diaktifkan, atau user API tidak punya policy untuk '
                . 'membacanya. Pesan router: ' . $rows['after']['message']
            );
        }

        $needle = strtolower(trim((string) config('services.mikrotik.speedtest_container', 'speedtest')));

        if ($needle === '') {
            return null;
        }

        foreach ($rows as $key => $row) {
            if (! is_int($key) || ! is_array($row)) {
                continue;
            }

            $container = $this->mapContainer($row);

            $haystack = strtolower(implode(' ', [
                $container['name'] ?? '',
                $container['tag'] ?? '',
                $container['root_dir'] ?? '',
            ]));

            if (str_contains($haystack, $needle)) {
                return $container;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>  $row
     * @return array<string,mixed>
     */
    protected function mapContainer(array $row): array
    {
        $status = $row['status'] ?? null;

        return [
            'id' => $row['.id'] ?? null,
            'name' => $row['name'] ?? null,
            'tag' => $row['tag'] ?? null,
            'root_dir' => $row['root-dir'] ?? null,
            'interface' => $row['interface'] ?? null,
            'envlist' => $row['envlist'] ?? null,
            'cmd' => $row['cmd'] ?? ($row['entrypoint'] ?? null),
            'status' => $status,
            'logging' => ($row['logging'] ?? 'false') === 'true',
            // "extracting" dan "starting" belum menghasilkan keluaran apa pun,
            // tapi tetap berarti container sedang dipakai dan tidak boleh
            // di-start ulang.
            'running' => in_array($status, ['running', 'starting', 'extracting'], true),
        ];
    }

    /**
     * .id baris log terakhir sebelum container dijalankan.
     *
     * Keluaran container dibaca dari /log, dan /log adalah ring buffer dengan
     * kolom waktu berformat "aug/31 09:13:51" — tanpa tahun, tanpa zona, dan
     * ikut bergeser kalau jam router tidak sinkron. Menandai batas dengan .id
     * baris terakhir membuat "baris baru" jadi selisih himpunan biasa, tanpa
     * mengurai format waktu RouterOS sama sekali.
     */
    protected function logBaseline(?string $host): ?string
    {
        try {
            $rows = $this->mikrotik->client($host)->query('/log/print')->read();
        } catch (\Throwable $e) {
            return null;
        }

        $last = null;

        foreach ($rows as $key => $row) {
            if (is_int($key) && is_array($row) && isset($row['.id'])) {
                $last = $row['.id'];
            }
        }

        return $last;
    }

    /**
     * Baris log yang muncul setelah $baseline.
     *
     * Kalau $baseline sudah tidak ada di log (ring buffer berputar selama
     * speedtest berjalan), seluruh baris yang tersisa dipakai — kelebihan
     * konteks jauh lebih murah daripada kehilangan hasil pengukuran.
     *
     * @return array<int,string>
     */
    protected function newLogLines(?string $host, ?string $baseline): array
    {
        try {
            $rows = $this->mikrotik->client($host)->query('/log/print')->read();
        } catch (\Throwable $e) {
            return [];
        }

        $entries = [];

        foreach ($rows as $key => $row) {
            if (is_int($key) && is_array($row)) {
                $entries[] = $row;
            }
        }

        $startIndex = 0;

        if ($baseline !== null) {
            foreach ($entries as $index => $entry) {
                if (($entry['.id'] ?? null) === $baseline) {
                    $startIndex = $index + 1;
                    break;
                }
            }
        }

        $fresh = array_slice($entries, $startIndex);

        $fromContainer = array_filter(
            $fresh,
            fn ($entry) => str_contains(strtolower((string) ($entry['topics'] ?? '')), 'container')
        );

        // Topik log container berbeda antar versi RouterOS (ada yang "container",
        // ada yang menaruhnya di "info"). Kalau tidak ada satu pun baris bertopik
        // container, seluruh baris baru dipakai — kalau tidak, hasil yang sudah
        // ada di log justru terbuang karena nama topiknya tidak sesuai dugaan.
        $chosen = $fromContainer !== [] ? $fromContainer : $fresh;

        $messages = [];

        foreach ($chosen as $entry) {
            $message = trim((string) ($entry['message'] ?? ''));

            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Ambil angka dari keluaran speedtest.
     *
     * Ada tiga format yang beredar dan ketiganya harus terbaca, karena mana yang
     * muncul tergantung image mana yang di-pull operator ke router:
     *   1. speedtest-cli (python) polos  → "Download: 94.35 Mbit/s"
     *   2. speedtest-cli --json          → satu baris JSON, satuan bit/detik
     *   3. CLI resmi Ookla               → "Download:  94.35 Mbps", "Latency: 23.45 ms"
     * Mengunci parser ke satu format berarti fitur ini mati begitu image-nya
     * diganti, jadi ketiganya dicoba berurutan.
     *
     * @param  array<int,string>  $lines
     * @return array<string,mixed>|null null bila download/upload belum lengkap.
     */
    public function parseOutput(array $lines): ?array
    {
        $json = $this->parseJsonOutput($lines);

        if ($json !== null) {
            return $json;
        }

        $text = implode("\n", $lines);

        $download = $this->matchSpeed($text, 'download');
        $upload = $this->matchSpeed($text, 'upload');

        // Download tanpa upload berarti putaran masih berjalan, bukan gagal —
        // maka null, supaya pemanggil terus menunggu alih-alih menyimpan hasil
        // separuh sebagai hasil akhir.
        if ($download === null || $upload === null) {
            return null;
        }

        return [
            'download_mbps' => $download,
            'upload_mbps' => $upload,
            'ping_ms' => $this->matchPing($text),
            // speedtest-cli menulis "Testing from <ISP> (ip)", CLI Ookla menulis
            // "ISP: <nama>" pada barisnya sendiri.
            //
            // Pola kedua TIDAK boleh dipatok ke awal baris: setiap baris yang
            // sampai ke /log sudah diberi awalan nama container oleh RouterOS
            // ("speedtest-cli:latest:          ISP: PT Telkom Indonesia"), jadi
            // patokan ^ membuat ISP dan nama server selalu kosong padahal
            // keduanya jelas ada di keluaran.
            'isp' => $this->matchFirst($text, '/testing from\s+(.+?)\s*\(/i')
                ?? $this->matchFirst($text, '/(?:^|\s)isp:\s*(.+?)\s*$/im'),
            'server' => $this->matchFirst($text, '/hosted by\s+(.+?)\s*(?:\[|:)/i')
                ?? $this->matchFirst($text, '/(?:^|\s)server:\s*(.+?)\s*$/im'),
        ];
    }

    /**
     * Satuan wajib ikut dibaca dan dinormalkan ke Mbps. Image yang berbeda
     * melaporkan Mbit/s, Mbps, bahkan Kbit/s pada sambungan lambat — mengabaikan
     * satuannya membuat 850 Kbit/s tercatat sebagai 850 Mbps.
     */
    protected function matchSpeed(string $text, string $direction): ?float
    {
        $pattern = '/'.$direction.'[^0-9\n]{0,24}([0-9]+(?:[.,][0-9]+)?)\s*(g|m|k)?(?:bit\/s|b\/s|bps)/i';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $value = (float) str_replace(',', '.', $m[1]);

        return match (strtolower($m[2] ?? 'm')) {
            'g' => round($value * 1000, 2),
            'k' => round($value / 1000, 2),
            default => round($value, 2),
        };
    }

    protected function matchPing(string $text): ?float
    {
        foreach ([
            // CLI Ookla: "Latency:     23.45 ms". speedtest-cli polos juga
            // memakai kata "Ping" pada mode --simple.
            '/(?:latency|ping)[^0-9\n]{0,24}([0-9]+(?:[.,][0-9]+)?)\s*ms/i',
            // speedtest-cli polos: "Hosted by Biznet (Jakarta) [12.34 km]: 23.456 ms"
            '/\]:\s*([0-9]+(?:[.,][0-9]+)?)\s*ms/',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return (float) str_replace(',', '.', $m[1]);
            }
        }

        return null;
    }

    /**
     * speedtest-cli --json melaporkan download/upload dalam bit per detik, bukan
     * Mbit — 94350000 harus jadi 94.35, bukan 94350000. Kalau nilai mentahnya
     * dipakai langsung, kolom decimal(8,2) akan overflow dan baris gagal disimpan.
     *
     * @param  array<int,string>  $lines
     * @return array<string,mixed>|null
     */
    protected function parseJsonOutput(array $lines): ?array
    {
        foreach ($lines as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '{')) {
                continue;
            }

            $data = json_decode($line, true);

            if (! is_array($data) || ! isset($data['download'], $data['upload'])) {
                continue;
            }

            $server = trim(
                ($data['server']['sponsor'] ?? '').' '.($data['server']['name'] ?? '')
            );

            return [
                'download_mbps' => round(((float) $data['download']) / 1000000, 2),
                'upload_mbps' => round(((float) $data['upload']) / 1000000, 2),
                'ping_ms' => isset($data['ping']) ? (float) $data['ping'] : null,
                'isp' => $data['client']['isp'] ?? null,
                'server' => $server !== '' ? $server : null,
            ];
        }

        return null;
    }

    protected function matchFirst(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }

    /**
     * Tahap yang sedang dikerjakan container beserta angka yang SUDAH terbaca.
     *
     * speedtest-cli menyelesaikan pengukurannya bertahap dan mencetak tiap hasil
     * begitu selesai — latensi lebih dulu, lalu download, baru upload. Artinya
     * angka download sudah bisa ditampilkan puluhan detik sebelum putaran
     * berakhir, dan itulah bedanya dengan sekadar menunggu satu baris terakhir.
     *
     * Urutan pemeriksaan dari belakang ke depan: yang menentukan tahap adalah
     * petunjuk TERAKHIR yang muncul di log, bukan yang pertama.
     *
     * @param  array<int,string>  $lines
     * @return array<string,mixed>
     */
    public function progress(array $lines): array
    {
        $text = implode("\n", $lines);

        $ping = $this->matchPing($text);
        $download = $this->matchSpeed($text, 'download');
        $upload = $this->matchSpeed($text, 'upload');

        $stage = match (true) {
            $upload !== null => 'upload',
            (bool) preg_match('/testing upload|uploading/i', $text) => 'upload',
            // Download selesai berarti yang berikutnya upload, walaupun baris
            // "Testing upload" belum tercetak.
            $download !== null => 'upload',
            (bool) preg_match('/testing download|downloading/i', $text) => 'download',
            $ping !== null => 'download',
            (bool) preg_match('/selecting best server|server list/i', $text) => 'latency',
            (bool) preg_match('/testing from|configuration|speedtest by ookla/i', $text) => 'config',
            default => 'starting',
        };

        return [
            'stage' => $stage,
            'ping_ms' => $ping,
            'download_mbps' => $download,
            'upload_mbps' => $upload,
        ];
    }

    /**
     * Hentikan container dan tutup putaran yang sedang tercatat.
     *
     * Putaran ditandai 'stopped', bukan dihapus dari cache: baris log yang sudah
     * terkumpul adalah satu-satunya keterangan mengapa operator sampai perlu
     * membatalkannya, dan itu harus tetap terbaca di halaman.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    public function stop(?string $host = null): array
    {
        $container = $this->findContainer($host);

        if ($container === null) {
            throw new RuntimeException('Container speedtest tidak ditemukan di router ini.');
        }

        $this->mikrotik->stopContainer($host, $container['id']);

        $key = $this->cacheKey($host);
        $run = Cache::get($key);

        if (is_array($run) && ($run['state'] ?? null) === 'running') {
            $run['state'] = 'stopped';
            $run['error'] = 'Putaran dihentikan dari aplikasi sebelum hasilnya lengkap.';
            $run['lines'] = $this->newLogLines($host, $run['log_baseline'] ?? null);

            Cache::put($key, $run, now()->addHour());
        }

        return $this->status($host);
    }

    /**
     * Hentikan lalu jalankan ulang container.
     *
     * RouterOS tidak menyelesaikan /container/stop seketika — statusnya melewati
     * "stopping" lebih dulu, dan /container/start pada container yang belum benar
     * -benar berhenti akan ditolak. Karena itu status ditunggu sebentar, dengan
     * batas tegas: kalau dalam 8 detik belum berhenti, lebih baik operator
     * diberitahu untuk menekan Stop lalu Start terpisah daripada request ini
     * menggantung tanpa batas.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    public function restart(?string $host = null): array
    {
        $container = $this->findContainer($host);

        if ($container === null) {
            throw new RuntimeException('Container speedtest tidak ditemukan di router ini.');
        }

        if ($container['running']) {
            $this->mikrotik->stopContainer($host, $container['id']);

            $stopped = false;

            for ($attempt = 0; $attempt < 8; $attempt++) {
                $this->wait(1);

                $current = $this->findContainer($host);

                if ($current === null || ! $current['running']) {
                    $stopped = true;
                    break;
                }
            }

            if (! $stopped) {
                throw new RuntimeException(
                    'Container belum berhenti setelah 8 detik. Tekan Stop, tunggu statusnya menjadi '
                    . '"stopped", lalu jalankan Start.'
                );
            }
        }

        Cache::forget($this->cacheKey($host));

        return $this->start($host);
    }

    /**
     * Jeda antar pemeriksaan status saat menunggu container berhenti.
     *
     * Dipisah jadi method sendiri semata-mata supaya bisa dinolkan di test:
     * menguji cabang "container tidak mau berhenti" tidak boleh berarti test
     * ikut menunggu delapan detik sungguhan.
     */
    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Baris log bertopik container, terbaru di atas — padanan tab "Log" di Winbox.
     *
     * Berdiri sendiri di luar putaran: keluhan tersering pada container adalah
     * gagal sebelum sempat mengukur apa pun (DNS, veth, NAT), dan penyebabnya
     * sudah tertulis di log jauh sebelum ada putaran baru dijalankan. Memaksa
     * operator menekan Start hanya untuk bisa melihat log adalah kebalikan dari
     * yang dibutuhkan.
     *
     * @return array<int,array<string,mixed>>
     */
    public function containerLog(?string $host = null, int $limit = 60): array
    {
        $rows = $this->mikrotik->getLogs($host, 400);

        $filtered = array_values(array_filter(
            $rows,
            fn ($row) => str_contains(strtolower((string) ($row['topics'] ?? '')), 'container')
        ));

        return array_slice($filtered, 0, $limit);
    }

    /**
     * Jalankan satu putaran speedtest di router.
     *
     * @return array<string,mixed> keadaan putaran yang baru dimulai.
     *
     * @throws RuntimeException bila container tidak ada, sedang berjalan, atau
     *                          keluarannya mustahil dibaca.
     */
    public function start(?string $host = null): array
    {
        $key = $this->cacheKey($host);

        // Dua admin yang menekan tombol pada detik yang sama akan menjalankan dua
        // speedtest di uplink yang sama dan saling merusak hasil satu sama lain.
        // Lock ini menutup celah antara pemeriksaan status dan perintah start.
        $lock = Cache::lock($key.':lock', 30);

        if (! $lock->get()) {
            throw new RuntimeException('Ada permintaan speedtest lain yang sedang diproses untuk router ini.');
        }

        try {
            $existing = Cache::get($key);

            if (is_array($existing) && ($existing['state'] ?? null) === 'running') {
                throw new RuntimeException(
                    'Sudah ada putaran speedtest yang berjalan untuk router ini. '
                    . 'Tunggu sampai selesai atau lewat batas waktu.'
                );
            }

            $container = $this->findContainer($host);

            if ($container === null) {
                throw new RuntimeException(
                    'Tidak ada container yang name/tag/root-dir-nya memuat "'
                    . config('services.mikrotik.speedtest_container')
                    . '" di router ini. Sesuaikan MIKROTIK_SPEEDTEST_CONTAINER di .env '
                    . 'dengan container speedtest yang sebenarnya ada di router.'
                );
            }

            if ($container['running']) {
                throw new RuntimeException(
                    'Container speedtest sedang berjalan di router (status: '.$container['status'].'). '
                    . 'Tunggu putaran itu selesai sebelum memulai yang baru.'
                );
            }

            // Keluaran container hanya sampai ke /log kalau logging container
            // aktif. Tanpa itu tombol ini akan "berhasil" tapi hasilnya tidak
            // akan pernah terbaca, jadi lebih baik ditolak sekarang beserta
            // perintah perbaikannya daripada berakhir sebagai timeout misterius.
            if (! $container['logging']) {
                throw new RuntimeException(
                    'Container ini belum logging=yes, sehingga keluaran speedtest tidak masuk ke /log '
                    . 'dan tidak bisa dibaca aplikasi. Jalankan di router: /container/set '
                    . $container['id'].' logging=yes'
                );
            }

            $baseline = $this->logBaseline($host);

            $this->mikrotik->startContainer($host, $container['id']);

            $run = [
                'state' => 'running',
                'container_id' => $container['id'],
                'started_at' => time(),
                'log_baseline' => $baseline,
                'elapsed' => 0,
                'lines' => [],
                // Jejak pertumbuhan /log. Keduanya diisi sejak awal supaya poll()
                // punya titik acuan pada putaran pertama, bukan menganggap log
                // sudah lama sunyi hanya karena kuncinya belum ada.
                'line_count' => 0,
                'last_growth_at' => time(),
                'quiet_for' => 0,
                'progress' => [
                    'stage' => 'starting',
                    'ping_ms' => null,
                    'download_mbps' => null,
                    'upload_mbps' => null,
                ],
                'result' => null,
                'result_id' => null,
                'error' => null,
            ];

            Cache::put($key, $run, now()->addHour());

            return $run;
        } finally {
            $lock->release();
        }
    }

    /**
     * Perkembangan putaran yang sedang berjalan. Aman dipanggil berulang: begitu
     * putaran selesai, hasilnya diambil dari cache dan router tidak dihubungi lagi.
     *
     * @return array<string,mixed>
     */
    public function poll(?string $host = null): array
    {
        $key = $this->cacheKey($host);
        $run = Cache::get($key);

        if (! is_array($run)) {
            return ['state' => 'idle'];
        }

        if (($run['state'] ?? null) !== 'running') {
            return $run;
        }

        $lines = $this->newLogLines($host, $run['log_baseline'] ?? null);
        $run['lines'] = $lines;
        $run['elapsed'] = time() - (int) ($run['started_at'] ?? time());
        // Dihitung sebelum semua percabangan di bawah, supaya angka separuh jalan
        // ikut terbawa pada putaran yang gagal juga: "download terukur 94 Mbps lalu
        // mati di upload" adalah keterangan yang jauh lebih berguna daripada
        // "gagal" tanpa konteks.
        $run['progress'] = $this->progress($lines);

        // Kapan terakhir /log bertambah. Dipakai untuk membedakan "container mati
        // di tengah jalan" dari "container masih bekerja tapi memang belum
        // mencetak apa-apa" — CLI Ookla dengan --progress=no diam belasan detik
        // selama fase download, dan diam bukan berarti mati.
        if (count($lines) > (int) ($run['line_count'] ?? 0)) {
            $run['line_count'] = count($lines);
            $run['last_growth_at'] = time();
        }

        $quietFor = time() - (int) ($run['last_growth_at'] ?? $run['started_at'] ?? time());
        // Ikut dikirim ke halaman: fase download dan upload Ookla berjalan tanpa
        // mencetak apa pun, dan tanpa keterangan ini jeda belasan detik terlihat
        // seperti putaran yang macet.
        $run['quiet_for'] = $quietFor;

        $parsed = $this->parseOutput($lines);

        // Selesai begitu download DAN upload terbaca. Tidak menunggu container
        // berstatus stopped: pengukuran sudah selesai pada titik itu, dan
        // container masih butuh beberapa detik lagi untuk keluar.
        if ($parsed !== null) {
            $run['state'] = 'done';
            $run['progress']['stage'] = 'done';
            $run['result'] = $parsed;
            $run['result_id'] = $this->persist($host, $parsed, $lines)->id;
            $run['finished_at'] = time();

            Cache::put($key, $run, now()->addHour());

            return $run;
        }

        $container = null;

        try {
            $container = $this->findContainer($host);
        } catch (\Throwable $e) {
            // Router sesaat tidak menjawab bukan alasan membatalkan putaran yang
            // mungkin sedang berjalan baik-baik saja; batas waktu di bawah yang
            // menutupnya.
        }

        $timeout = (int) config('services.mikrotik.speedtest_timeout', 180);

        // Sudah ada tanda pengukuran benar-benar berjalan? Baris latensi, nama
        // server, atau nama ISP hanya tercetak setelah container berhasil
        // menghubungi speedtest.net — jadi keberadaannya memisahkan dua kegagalan
        // yang butuh penanganan sangat berbeda.
        $measuring = $run['progress']['ping_ms'] !== null
            || in_array($run['progress']['stage'], ['latency', 'download', 'upload'], true);

        // Container keluar SEBELUM sempat mengukur apa pun: hampir selalu berarti
        // gagal jalan (DNS, veth, atau NAT), dan itu terjadi dalam hitungan detik.
        // Kasus ini memang layak dinyatakan gagal cepat, lengkap dengan baris
        // lognya sebagai keterangan.
        //
        // Toleransi 5 detik tetap perlu: tepat setelah /container/start, status di
        // /container/print bisa masih "stopped" karena RouterOS belum memperbaruinya.
        if ($container !== null && ! $container['running'] && ! $measuring && $run['elapsed'] > 5) {
            $run['state'] = 'failed';
            $run['error'] = 'Container sudah berhenti sebelum sempat mengukur apa pun. '
                . 'Periksa baris log di bawah — penyebab tersering: container tidak dapat resolusi DNS, '
                . 'atau tidak punya jalur NAT/masquerade ke internet dari interface veth-nya.';

            Cache::put($key, $run, now()->addHour());

            return $run;
        }

        // Pengukuran SUDAH berjalan tapi status container bilang tidak jalan:
        // status itu tidak boleh dipakai untuk membatalkan putaran. Dua hal nyata
        // membuatnya berbohong — RouterOS menulis stdout container ke /log secara
        // asinkron, sehingga baris Download/Upload bisa muncul setelah container
        // tercatat keluar; dan status di /container/print sendiri kadang tertinggal
        // dari keadaan sebenarnya. Membatalkan di titik ini berarti membuang
        // pengukuran yang di /log router justru selesai dengan lengkap.
        //
        // Maka yang dipakai adalah kesunyian log, bukan status: selama /log masih
        // bertambah, putaran diteruskan. Ambangnya 45 detik — lebih panjang dari
        // fase download maupun upload mana pun, yang keduanya memang tidak
        // mencetak apa-apa selama berlangsung.
        if ($measuring && $quietFor >= 45) {
            $run['state'] = 'failed';
            $run['error'] = 'Pengukuran sempat berjalan tetapi berhenti sebelum angka download dan upload '
                . 'lengkap, dan sudah '.$quietFor.' detik tidak ada baris baru di /log. '
                . 'Periksa baris log di bawah; kalau di Winbox log-nya justru sampai selesai, '
                . 'kemungkinan container kehilangan koneksi di tengah pengukuran.';

            Cache::put($key, $run, now()->addHour());

            return $run;
        }

        if ($run['elapsed'] > $timeout) {
            $run['state'] = 'failed';
            $run['error'] = "Melewati batas {$timeout} detik tanpa hasil yang bisa dibaca dari /log.";

            Cache::put($key, $run, now()->addHour());

            return $run;
        }

        Cache::put($key, $run, now()->addHour());

        return $run;
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @param  array<int,string>  $lines
     */
    protected function persist(?string $host, array $parsed, array $lines): SpeedtestResult
    {
        return SpeedtestResult::create([
            'source' => self::SOURCE,
            'download_speed_mbps' => $parsed['download_mbps'],
            'upload_speed_mbps' => $parsed['upload_mbps'],
            // Tidak semua image melaporkan latensi. Menyimpan 0 ms untuk hasil
            // yang latensinya tidak dilaporkan sama dengan mengarang angka, jadi
            // kolomnya dibiarkan null.
            'ping_ms' => $parsed['ping_ms'] !== null ? (int) round($parsed['ping_ms']) : null,
            'isp' => $parsed['isp'],
            'router_host' => $this->hostKey($host),
            'server_name' => $parsed['server'],
            // Keluaran mentah selalu ikut disimpan: parser ini toleran, tapi tetap
            // bisa melewatkan format baru, dan tanpa teks aslinya tidak ada cara
            // memastikan angka yang tersimpan benar.
            'raw_output' => implode("\n", $lines),
        ]);
    }

    /**
     * Keadaan fitur untuk halaman: ada/tidaknya container beserta kesiapannya,
     * putaran yang sedang berjalan, dan hasil terakhir dari router ini.
     *
     * Kegagalan membaca /container dikembalikan sebagai teks di 'error', bukan
     * exception — halaman tetap harus terbuka dan justru harus menjelaskan apa
     * yang perlu dibetulkan di router.
     *
     * @return array<string,mixed>
     */
    public function status(?string $host = null): array
    {
        $container = null;
        $error = null;

        try {
            $container = $this->findContainer($host);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $hostKey = $this->hostKey($host);
        $run = Cache::get($this->cacheKey($host));

        return [
            'host' => $hostKey,
            'pattern' => (string) config('services.mikrotik.speedtest_container'),
            'timeout' => (int) config('services.mikrotik.speedtest_timeout', 180),
            'container' => $container,
            'error' => $error,
            'run' => is_array($run) ? $run : null,
            'last_result' => SpeedtestResult::query()
                ->where('source', self::SOURCE)
                ->where('router_host', $hostKey)
                ->latest()
                ->first(),
        ];
    }
}
