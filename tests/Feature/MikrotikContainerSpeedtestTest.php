<?php

namespace Tests\Feature;

use App\Models\SpeedtestResult;
use App\Services\MikrotikContainerSpeedtestService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\FakeContainerSpeedtestService;
use Tests\TestCase;

/**
 * Dua hal yang diuji di sini, dan keduanya adalah tempat fitur ini paling mudah
 * salah tanpa terlihat salah:
 *
 * 1. Parser keluaran container. Angka yang salah baca tetap tersimpan sebagai
 *    angka yang tampak wajar — 850 Kbit/s yang tercatat 850 Mbps tidak akan
 *    memicu error apa pun, hanya laporan uplink yang bohong.
 * 2. Keputusan alur putaran. Menyatakan "selesai" terlalu cepat berarti menyimpan
 *    hasil separuh; menyatakan "gagal" terlalu cepat berarti fitur ini tidak
 *    pernah berhasil sekali pun.
 */
class MikrotikContainerSpeedtestTest extends TestCase
{
    use RefreshDatabase;

    /** Mock MikrotikService yang dipakai fake() terakhir. */
    private $mikrotik;

    /**
     * Argumen setiap pemanggilan start/stop container, supaya bisa dipastikan
     * bahwa tombol Stop benar-benar mengirim /container/stop dengan .id yang
     * benar — bukan hanya mengubah state di cache.
     *
     * @var array<int,array{0:?string,1:string}>
     */
    private array $startCalls = [];

    /** @var array<int,array{0:?string,1:string}> */
    private array $stopCalls = [];

    /** Baris /log mentah yang dijawab MikrotikService::getLogs().
     *
     * @var array<int,array<string,mixed>>
     */
    private array $fakeLogRows = [];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function fake(): FakeContainerSpeedtestService
    {
        $this->mikrotik = Mockery::mock(MikrotikService::class);

        $this->mikrotik->shouldReceive('startContainer')
            ->andReturnUsing(function ($host, $id) {
                $this->startCalls[] = [$host, $id];
            });

        $this->mikrotik->shouldReceive('stopContainer')
            ->andReturnUsing(function ($host, $id) {
                $this->stopCalls[] = [$host, $id];
            });

        $this->mikrotik->shouldReceive('getLogs')
            ->andReturnUsing(fn () => $this->fakeLogRows);

        return new FakeContainerSpeedtestService($this->mikrotik);
    }

    private function parser(): MikrotikContainerSpeedtestService
    {
        return $this->fake();
    }

    public function test_keluaran_speedtest_cli_polos_terbaca(): void
    {
        $result = $this->parser()->parseOutput([
            'Retrieving speedtest.net configuration...',
            'Testing from Biznet Networks (103.28.14.5)...',
            'Retrieving speedtest.net server list...',
            'Selecting best server based on ping...',
            'Hosted by Biznet Networks (Jakarta) [12.34 km]: 23.456 ms',
            'Testing download speed................................................',
            'Download: 94.35 Mbit/s',
            'Testing upload speed......................................',
            'Upload: 47.12 Mbit/s',
        ]);

        $this->assertSame(94.35, $result['download_mbps']);
        $this->assertSame(47.12, $result['upload_mbps']);
        $this->assertSame(23.456, $result['ping_ms']);
        $this->assertSame('Biznet Networks', $result['isp']);
        $this->assertSame('Biznet Networks (Jakarta)', $result['server']);
    }

    /**
     * Baris "Testing download speed......" muncul SEBELUM baris hasilnya dan juga
     * memuat kata "download". Parser harus melewatinya, bukan berhenti di sana
     * dan menyimpulkan hasilnya belum ada.
     */
    public function test_baris_progres_tidak_dianggap_sebagai_hasil(): void
    {
        $result = $this->parser()->parseOutput([
            'Testing download speed................................................',
            'Download: 12.50 Mbit/s',
            'Testing upload speed................................',
            'Upload: 3.25 Mbit/s',
        ]);

        $this->assertSame(12.5, $result['download_mbps']);
        $this->assertSame(3.25, $result['upload_mbps']);
    }

    public function test_keluaran_cli_ookla_terbaca(): void
    {
        $result = $this->parser()->parseOutput([
            'Speedtest by Ookla',
            '     Server: Biznet - Jakarta',
            '        ISP: Biznet Networks',
            '    Latency:    23.45 ms   (0.12 ms jitter)',
            '   Download:   940.11 Mbps (data used: 1.1 GB)',
            '     Upload:   468.20 Mbps (data used: 512.4 MB)',
        ]);

        $this->assertSame(940.11, $result['download_mbps']);
        $this->assertSame(468.20, $result['upload_mbps']);
        $this->assertSame(23.45, $result['ping_ms']);
        $this->assertSame('Biznet - Jakarta', $result['server']);
    }

    /**
     * Format --json melaporkan bit per detik. Dipakai apa adanya, 94350000 akan
     * meluap dari kolom decimal(8,2) dan barisnya gagal disimpan — kegagalan yang
     * baru terlihat di produksi.
     */
    public function test_keluaran_json_dikonversi_dari_bit_per_detik(): void
    {
        $result = $this->parser()->parseOutput([
            'Retrieving speedtest.net configuration...',
            json_encode([
                'download' => 94350000.0,
                'upload' => 47120000.0,
                'ping' => 23.456,
                'server' => ['sponsor' => 'Biznet', 'name' => 'Jakarta'],
                'client' => ['isp' => 'Biznet Networks'],
            ]),
        ]);

        $this->assertEqualsWithDelta(94.35, $result['download_mbps'], 0.001);
        $this->assertEqualsWithDelta(47.12, $result['upload_mbps'], 0.001);
        $this->assertSame(23.456, $result['ping_ms']);
        $this->assertSame('Biznet Networks', $result['isp']);
        $this->assertSame('Biznet Jakarta', $result['server']);
    }

    /**
     * @param  array<int,string>  $lines
     */
    #[DataProvider('unitCases')]
    public function test_satuan_dinormalkan_ke_mbps(array $lines, float $expected): void
    {
        $this->assertSame($expected, $this->parser()->parseOutput($lines)['download_mbps']);
    }

    /**
     * @return array<string,array{0:array<int,string>,1:float}>
     */
    public static function unitCases(): array
    {
        return [
            'Mbit/s' => [['Download: 94.35 Mbit/s', 'Upload: 1 Mbit/s'], 94.35],
            // Tanpa normalisasi, uplink 850 Kbit/s akan dilaporkan 850 Mbps.
            'Kbit/s' => [['Download: 850.00 Kbit/s', 'Upload: 1 Mbit/s'], 0.85],
            'Gbit/s' => [['Download: 1.05 Gbit/s', 'Upload: 1 Mbit/s'], 1050.0],
            'Mbps' => [['Download: 94.35 Mbps', 'Upload: 1 Mbps'], 94.35],
            'koma sebagai desimal' => [['Download: 94,35 Mbit/s', 'Upload: 1 Mbit/s'], 94.35],
        ];
    }

    /**
     * Download tanpa upload berarti putaran masih berjalan. Kalau ini dianggap
     * hasil, yang tersimpan adalah pengukuran separuh jalan.
     */
    public function test_hasil_setengah_jalan_belum_dianggap_selesai(): void
    {
        $this->assertNull($this->parser()->parseOutput([
            'Hosted by Biznet (Jakarta) [12.34 km]: 23.456 ms',
            'Download: 94.35 Mbit/s',
        ]));
    }

    public function test_keluaran_tanpa_angka_tidak_menghasilkan_apa_pun(): void
    {
        $this->assertNull($this->parser()->parseOutput([
            'Cannot retrieve speedtest configuration',
            'ERROR: Could not resolve host: www.speedtest.net',
        ]));
    }

    /**
     * Tidak semua image melaporkan latensi. Hasil download/upload yang sah tidak
     * boleh dibuang hanya karena baris latensinya tidak ada — dan latensinya juga
     * tidak boleh dikarang jadi 0 ms.
     */
    public function test_latensi_yang_tidak_dilaporkan_menjadi_null(): void
    {
        $result = $this->parser()->parseOutput([
            'Download: 94.35 Mbit/s',
            'Upload: 47.12 Mbit/s',
        ]);

        $this->assertSame(94.35, $result['download_mbps']);
        $this->assertNull($result['ping_ms']);
    }

    public function test_start_ditolak_bila_container_tidak_ada(): void
    {
        $service = $this->fake();
        $service->fakeContainer = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MIKROTIK_SPEEDTEST_CONTAINER/');

        $service->start('192.168.91.1');
    }

    public function test_start_ditolak_bila_container_sedang_berjalan(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('running');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sedang berjalan/');

        $service->start('192.168.91.1');
    }

    /**
     * Tanpa logging=yes keluaran container tidak pernah sampai ke /log, jadi
     * putaran itu pasti berakhir sebagai timeout tanpa sebab yang jelas. Lebih
     * baik ditolak di depan, beserta perintah perbaikannya.
     */
    public function test_start_ditolak_bila_logging_container_belum_aktif(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped', logging: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#/container/set \*1 logging=yes#');

        $service->start('192.168.91.1');
    }

    public function test_start_menyimpan_keadaan_putaran_dan_penanda_log(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer();

        $run = $service->start('192.168.91.1');

        $this->assertSame('running', $run['state']);
        $this->assertSame('*1', $run['container_id']);
        $this->assertSame('*100', $run['log_baseline']);
        $this->assertSame($run, Cache::get('mikrotik_speedtest_run:192.168.91.1'));
    }

    /**
     * Host dengan dan tanpa port adalah router yang sama. Kalau keduanya dihitung
     * sebagai kunci berbeda, dua putaran bisa berjalan bersamaan di satu uplink.
     */
    public function test_host_dengan_port_memakai_kunci_yang_sama(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer();

        $service->start('192.168.91.1:7111');

        $this->assertNotNull(Cache::get('mikrotik_speedtest_run:192.168.91.1'));
    }

    public function test_poll_tanpa_putaran_berstatus_idle(): void
    {
        $this->assertSame(['state' => 'idle'], $this->fake()->poll('192.168.91.1'));
    }

    public function test_poll_menyimpan_hasil_begitu_download_dan_upload_terbaca(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer();
        $service->start('192.168.91.1');

        $service->fakeLogLines = [
            'Testing from Biznet Networks (103.28.14.5)...',
            'Hosted by Biznet Networks (Jakarta) [12.34 km]: 23.456 ms',
            'Download: 94.35 Mbit/s',
            'Upload: 47.12 Mbit/s',
        ];

        $run = $service->poll('192.168.91.1');

        $this->assertSame('done', $run['state']);
        $this->assertNotNull($run['result_id']);

        $row = SpeedtestResult::find($run['result_id']);

        $this->assertSame(SpeedtestResult::SOURCE_ROUTER, $row->source);
        $this->assertSame(94.35, $row->download_speed_mbps);
        $this->assertSame(47.12, $row->upload_speed_mbps);
        $this->assertSame(23, $row->ping_ms);
        $this->assertSame('Biznet Networks', $row->isp);
        $this->assertSame('192.168.91.1', $row->router_host);
        // Teks asli selalu ikut tersimpan: tanpanya tidak ada cara memverifikasi
        // angka di atas kalau suatu hari format keluarannya berubah.
        $this->assertStringContainsString('Download: 94.35 Mbit/s', $row->raw_output);
    }

    /**
     * Putaran yang sudah selesai tidak boleh menghubungi router lagi — halaman
     * memanggil poll berkala, dan tanpa ini router terus di-query setelah
     * hasilnya ada.
     */
    public function test_poll_setelah_selesai_tidak_menghubungi_router_lagi(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer();
        $service->start('192.168.91.1');
        $service->fakeLogLines = ['Download: 10 Mbit/s', 'Upload: 5 Mbit/s'];
        $service->poll('192.168.91.1');

        $callsAfterDone = $service->findCalls;
        $service->poll('192.168.91.1');

        $this->assertSame($callsAfterDone, $service->findCalls);
        $this->assertSame(1, SpeedtestResult::count());
    }

    /**
     * Tepat setelah /container/start, status di /container/print bisa masih
     * "stopped" karena RouterOS belum memperbaruinya. Tanpa jeda toleransi, setiap
     * putaran akan langsung dinyatakan gagal pada poll pertama.
     */
    public function test_status_stopped_pada_detik_pertama_belum_dianggap_gagal(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        $service->fakeLogLines = ['Retrieving speedtest.net configuration...'];

        $this->assertSame('running', $service->poll('192.168.91.1')['state']);
    }

    public function test_container_berhenti_tanpa_angka_dilaporkan_gagal_beserta_lognya(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        $this->ageRun('192.168.91.1', 30);
        $service->fakeLogLines = ['ERROR: Could not resolve host: www.speedtest.net'];

        $run = $service->poll('192.168.91.1');

        $this->assertSame('failed', $run['state']);
        $this->assertStringContainsString('DNS', $run['error']);
        $this->assertSame(['ERROR: Could not resolve host: www.speedtest.net'], $run['lines']);
    }

    public function test_putaran_yang_melewati_batas_waktu_dinyatakan_gagal(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        $service->fakeContainer = $service->makeContainer('running');
        $this->ageRun('192.168.91.1', (int) config('services.mikrotik.speedtest_timeout') + 10);

        $run = $service->poll('192.168.91.1');

        $this->assertSame('failed', $run['state']);
        $this->assertStringContainsString('batas', $run['error']);
    }

    /**
     * Inti dari "bukan sekadar menunggu log": speedtest-cli mencetak hasilnya
     * bertahap, jadi tahap yang sedang dikerjakan dan angka yang sudah selesai
     * keduanya bisa dibaca di tengah putaran.
     */
    public function test_tahap_dibaca_dari_petunjuk_terakhir_di_log(): void
    {
        $service = $this->fake();

        $config = $service->progress(['Retrieving speedtest.net configuration...']);
        $this->assertSame('config', $config['stage']);
        $this->assertNull($config['download_mbps']);

        $latency = $service->progress([
            'Retrieving speedtest.net server list...',
            'Selecting best server based on ping...',
        ]);
        $this->assertSame('latency', $latency['stage']);

        // Baris latensi sudah tercetak berarti tahap berikutnya download, walaupun
        // baris "Testing download" belum muncul.
        $afterPing = $service->progress([
            'Hosted by Biznet (Jakarta) [12.34 km]: 23.456 ms',
        ]);
        $this->assertSame('download', $afterPing['stage']);
        $this->assertSame(23.456, $afterPing['ping_ms']);
    }

    /**
     * Angka download sudah final puluhan detik sebelum putaran berakhir. Kalau
     * ia tidak ikut dikembalikan, satu-satunya cara operator mengetahuinya adalah
     * membaca /log sendiri — persis keadaan yang mau dihilangkan.
     */
    public function test_download_yang_sudah_terukur_ikut_terbawa_sebelum_upload_selesai(): void
    {
        $service = $this->fake();

        $partial = $service->progress([
            'Hosted by Biznet (Jakarta) [12.34 km]: 23.456 ms',
            'Download: 94.35 Mbit/s',
        ]);

        $this->assertSame('upload', $partial['stage']);
        $this->assertSame(94.35, $partial['download_mbps']);
        $this->assertNull($partial['upload_mbps']);
    }

    public function test_poll_menyertakan_perkembangan_selama_putaran_berjalan(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer();
        $service->start('192.168.91.1');

        $service->fakeLogLines = [
            'Testing from Biznet Networks (103.28.14.5)...',
            'Hosted by Biznet Networks (Jakarta) [12.34 km]: 23.456 ms',
            'Download: 94.35 Mbit/s',
            'Testing upload speed......',
        ];

        $run = $service->poll('192.168.91.1');

        $this->assertSame('running', $run['state']);
        $this->assertSame('upload', $run['progress']['stage']);
        $this->assertSame(94.35, $run['progress']['download_mbps']);
        // Belum ada baris hasil upload, jadi belum ada baris tersimpan.
        $this->assertSame(0, SpeedtestResult::count());
    }

    /**
     * Putaran yang gagal setelah download terukur menunjuk masalah yang berbeda
     * dari yang gagal sejak awal, jadi angka separuh jalannya harus tetap terbawa
     * ke keadaan 'failed'.
     */
    public function test_putaran_gagal_tetap_membawa_angka_yang_sempat_terukur(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        // 60 detik tanpa baris baru: lebih lama dari fase download maupun upload
        // mana pun, jadi di titik ini kesunyian benar-benar berarti berhenti.
        // Poll pertama yang mencatat baris Download itu masuk sebagai pertumbuhan
        // log, jadi penuaan dilakukan sesudahnya — persis seperti di lapangan.
        $service->fakeLogLines = ['Download: 94.35 Mbit/s'];
        $service->poll('192.168.91.1');
        $this->ageRun('192.168.91.1', 90, 60);

        $run = $service->poll('192.168.91.1');

        $this->assertSame('failed', $run['state']);
        $this->assertSame(94.35, $run['progress']['download_mbps']);
        $this->assertSame(0, SpeedtestResult::count());
    }

    /**
     * Bug yang dilaporkan dari lapangan: di /log router speedtest berjalan sampai
     * selesai, tapi aplikasi menyatakan gagal.
     *
     * Penyebabnya, status container dipakai sebagai penentu. RouterOS menulis
     * stdout container ke /log secara asinkron dan status di /container/print
     * sendiri bisa tertinggal, jadi "tidak running" pada satu pembacaan bukan
     * bukti pengukuran berhenti. Selama pengukuran sudah jelas berjalan (latensi
     * atau nama server sudah tercetak) dan /log masih bertambah, putaran harus
     * diteruskan — kalau tidak, baris Download/Upload yang datang beberapa detik
     * kemudian ikut terbuang.
     */
    public function test_container_tercatat_berhenti_tetapi_log_masih_bertambah_tetap_diteruskan(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        // Persis keluaran yang dilaporkan: sudah sampai latensi, belum sampai
        // download. Container di /container/print sudah tercatat berhenti.
        $this->ageRun('192.168.91.1', 30, 2);
        $service->fakeLogLines = [
            'speedtest-cli:latest:    Speedtest by Ookla',
            'speedtest-cli:latest:       Server: PT. Telekomunikasi Indonesia - Bandung (id: 7580)',
            'speedtest-cli:latest:          ISP: PT Telkom Indonesia',
            'speedtest-cli:latest: Idle Latency:    22.81 ms   (jitter: 0.14ms, low: 22.68ms, high: 22.91ms)',
        ];

        $run = $service->poll('192.168.91.1');

        $this->assertSame('running', $run['state']);
        $this->assertSame(22.81, $run['progress']['ping_ms']);

        // Baris yang menyusul kemudian — inilah yang sebelumnya terbuang.
        $service->fakeLogLines[] = 'speedtest-cli:latest:     Download:   94.35 Mbit/s (data used: 112.4 MB)';
        $service->fakeLogLines[] = 'speedtest-cli:latest:       Upload:   47.12 Mbit/s (data used: 56.1 MB)';

        $run = $service->poll('192.168.91.1');

        $this->assertSame('done', $run['state']);
        $this->assertSame(94.35, $run['result']['download_mbps']);
        $this->assertSame(47.12, $run['result']['upload_mbps']);
        $this->assertSame(22.81, $run['result']['ping_ms']);
        $this->assertSame(1, SpeedtestResult::count());
    }

    /**
     * Sisi lain dari aturan yang sama: kalau pengukuran memang sudah berjalan tapi
     * /log berhenti bertambah lebih lama dari fase Ookla mana pun, putaran tidak
     * boleh menggantung sampai batas 180 detik.
     */
    public function test_log_yang_lama_sunyi_setelah_pengukuran_berjalan_dinyatakan_gagal(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        $service->fakeLogLines = ['Idle Latency:    22.81 ms'];
        $service->poll('192.168.91.1');
        $this->ageRun('192.168.91.1', 90, 70);

        $run = $service->poll('192.168.91.1');

        $this->assertSame('failed', $run['state']);
        $this->assertStringContainsString('/log', $run['error']);
        $this->assertSame(22.81, $run['progress']['ping_ms']);
    }

    /**
     * RouterOS memberi awalan nama container pada setiap baris stdout yang masuk ke
     * /log, sehingga pola yang dipatok ke awal baris tidak akan pernah cocok. ISP
     * dan nama server jelas ada di keluaran, jadi keduanya harus tetap terbaca dan
     * tersimpan — bukan menjadi NULL di database.
     */
    public function test_isp_dan_server_terbaca_walau_diawali_nama_container(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->start('192.168.91.1');

        $service->fakeLogLines = [
            'speedtest-cli:latest:       Server: PT. Telekomunikasi Indonesia - Bandung (id: 7580)',
            'speedtest-cli:latest:          ISP: PT Telkom Indonesia',
            'speedtest-cli:latest: Idle Latency:    22.81 ms',
            'speedtest-cli:latest:     Download:   94.35 Mbit/s',
            'speedtest-cli:latest:       Upload:   47.12 Mbit/s',
        ];

        $run = $service->poll('192.168.91.1');

        $this->assertSame('done', $run['state']);
        $this->assertSame('PT Telkom Indonesia', $run['result']['isp']);
        $this->assertSame('PT. Telekomunikasi Indonesia - Bandung (id: 7580)', $run['result']['server']);

        $saved = SpeedtestResult::firstOrFail();
        $this->assertSame('PT Telkom Indonesia', $saved->isp);
    }

    /**
     * Kebalikannya, "Selecting best server based on ping..." dan "Retrieving
     * speedtest.net server list..." tidak boleh terbaca sebagai nama server —
     * keduanya baris kemajuan, bukan hasil.
     */
    public function test_baris_pemilihan_server_tidak_dianggap_nama_server(): void
    {
        $service = $this->fake();

        $parsed = $service->parseOutput([
            'speedtest-cli:latest: Retrieving speedtest.net server list...',
            'speedtest-cli:latest: Selecting best server based on ping...',
            'speedtest-cli:latest: Download: 94.35 Mbit/s',
            'speedtest-cli:latest: Upload: 47.12 Mbit/s',
        ]);

        $this->assertNull($parsed['server']);
    }

    public function test_stop_mengirim_perintah_ke_router_dan_menutup_putaran(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer();
        $service->start('192.168.91.1');

        // Container sudah berjalan sejak start, dan log-nya baru sampai separuh.
        $service->fakeContainer = $service->makeContainer('running');
        $service->fakeLogLines = ['Download: 94.35 Mbit/s'];

        $status = $service->stop('192.168.91.1');

        $this->assertSame([['192.168.91.1', '*1']], $this->stopCalls);
        $this->assertSame('stopped', $status['run']['state']);
        // Baris log yang sudah terkumpul adalah satu-satunya keterangan mengapa
        // putaran ini sampai perlu dibatalkan, jadi tidak boleh ikut dibuang.
        $this->assertSame(['Download: 94.35 Mbit/s'], $status['run']['lines']);
        $this->assertSame(0, SpeedtestResult::count());
    }

    public function test_stop_ditolak_bila_container_tidak_ada(): void
    {
        $service = $this->fake();
        $service->fakeContainer = null;

        $this->expectException(\RuntimeException::class);

        $service->stop('192.168.91.1');
    }

    /**
     * /container/start ditolak selama container belum benar-benar berhenti, jadi
     * restart harus menunggu statusnya berubah lebih dulu — bukan langsung
     * menembakkan start setelah stop.
     */
    public function test_restart_menunggu_container_berhenti_sebelum_memulai_lagi(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('stopped');
        $service->fakeContainerQueue = [
            $service->makeContainer('running'),  // pemeriksaan awal restart()
            $service->makeContainer('running'),  // satu detik kemudian masih jalan
            // Setelah antrean habis, findContainer() menjawab $fakeContainer di
            // atas — yaitu sudah "stopped", sehingga penungguan berhenti di sini.
        ];

        $run = $service->restart('192.168.91.1');

        $this->assertSame([['192.168.91.1', '*1']], $this->stopCalls);
        $this->assertSame([['192.168.91.1', '*1']], $this->startCalls);
        $this->assertSame('running', $run['state']);
        $this->assertGreaterThan(0, $service->waitedSeconds);
        $this->assertNotNull(Cache::get('mikrotik_speedtest_run:192.168.91.1'));
    }

    /**
     * Kalau container tidak mau berhenti, request tidak boleh menggantung tanpa
     * batas; operator diberi instruksi manual dan start tidak pernah dikirim.
     */
    public function test_restart_menyerah_dengan_instruksi_bila_container_tidak_mau_berhenti(): void
    {
        $service = $this->fake();
        $service->fakeContainer = $service->makeContainer('running');

        try {
            $service->restart('192.168.91.1');
            $this->fail('restart() seharusnya melempar exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Stop', $e->getMessage());
        }

        $this->assertSame([], $this->startCalls);
        $this->assertLessThanOrEqual(8, $service->waitedSeconds);
    }

    /**
     * Padanan tab Log di Winbox: hanya baris bertopik container, dan bisa dibaca
     * tanpa menjalankan putaran apa pun.
     */
    public function test_log_container_menyaring_topik_lain(): void
    {
        $service = $this->fake();

        $this->fakeLogRows = [
            ['time' => '09:14:02', 'topics' => 'container,info', 'message' => 'Download: 94.35 Mbit/s'],
            ['time' => '09:14:01', 'topics' => 'dhcp,info', 'message' => 'deassigned 10.0.0.5'],
            ['time' => '09:13:55', 'topics' => 'container,error', 'message' => 'could not resolve host'],
        ];

        $lines = $service->containerLog('192.168.91.1');

        $this->assertCount(2, $lines);
        $this->assertSame('Download: 94.35 Mbit/s', $lines[0]['message']);
        $this->assertSame('could not resolve host', $lines[1]['message']);
    }

    /**
     * Geser waktu mulai putaran ke belakang, supaya cabang "sudah berjalan lama"
     * bisa diuji tanpa benar-benar menunggu.
     */
    private function ageRun(string $host, int $seconds, ?int $quietFor = null): void
    {
        $key = "mikrotik_speedtest_run:{$host}";
        $run = Cache::get($key);
        $run['started_at'] = time() - $seconds;
        // Secara baku log dianggap sunyi sejak putaran dimulai — itu keadaan yang
        // diuji hampir semua skenario kegagalan. Skenario "container tercatat
        // berhenti padahal /log masih bertambah" memberi $quietFor kecil, karena di
        // situ yang membedakan hidup dan mati justru pertumbuhan log, bukan usia
        // putaran.
        $run['last_growth_at'] = time() - ($quietFor ?? $seconds);

        Cache::put($key, $run, now()->addHour());
    }
}
