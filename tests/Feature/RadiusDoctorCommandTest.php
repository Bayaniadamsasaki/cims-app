<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * `radius:doctor` adalah gerbang sebelum CIMS menulis sebaris pun ke RADIUS.
 *
 * Database RADIUS bukan milik CIMS: skemanya dibuat FreeRADIUS, isinya bisa
 * sudah dipakai konfigurasi lain, dan servernya ada di seberang jaringan. Yang
 * diuji di sini bukan bunyi laporannya, melainkan satu keputusan yang menentukan
 * apakah proyek ini boleh lanjut — mana yang MENGHENTIKAN dan mana yang cuma
 * catatan:
 *
 *   - skema yang kurang (tabel atau kolom) → gagal, jangan menulis apa pun;
 *   - isi pendukung yang kurang (policy group, router di tabel nas) → catatan,
 *     karena mahasiswa masih bisa login, hanya tanpa batas atau tanpa paket.
 *
 * Perintahnya read-only, jadi test ini juga tidak pernah menulis ke RADIUS.
 */
class RadiusDoctorCommandTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    private const ROUTER = '198.51.100.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRadiusDatabase();

        // userinfo bukan tabel FreeRADIUS, jadi tidak ikut dibangun ulang — dan
        // sqlite :memory: hidup sepanjang proses PHPUnit. Tanpa baris ini, test
        // flavor daloRADIUS bocor ke test berikutnya.
        Schema::connection($this->radiusConnection)->dropIfExists('userinfo');

        config([
            'services.hotspot.radius.default_group' => 'mahasiswa',
            'services.hotspot.router_host' => self::ROUTER,
            'services.mikrotik.host' => null,
        ]);
    }

    public function test_a_complete_stock_schema_passes_every_check(): void
    {
        $this->seedRadiusGroup('mahasiswa');
        $this->registerNas(self::ROUTER);

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('FreeRADIUS rlm_sql stok')
            ->expectsOutputToContain('ada policy')
            ->expectsOutputToContain('Semua pemeriksaan lolos')
            ->assertExitCode(0);
    }

    public function test_a_missing_table_stops_the_gate_before_anything_is_written(): void
    {
        Schema::connection($this->radiusConnection)->drop('radusergroup');

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('TIDAK ADA')
            ->expectsOutputToContain('Jangan lanjut menulis')
            ->assertExitCode(1);
    }

    /**
     * Skema FreeRADIUS 2.x tidak punya kolom `priority` di radusergroup. Tabelnya
     * ada, jadi pemeriksaan "tabelnya ada" saja belum cukup: yang menentukan
     * adalah kolom yang benar-benar dipakai CIMS.
     */
    public function test_a_table_that_exists_but_lacks_a_column_still_fails(): void
    {
        $schema = Schema::connection($this->radiusConnection);
        $schema->drop('radusergroup');
        $schema->create('radusergroup', function (Blueprint $t) {
            $t->string('username', 64)->default('');
            $t->string('groupname', 64)->default('');
        });

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('kolom hilang: priority')
            ->expectsOutputToContain('Jangan lanjut menulis')
            ->assertExitCode(1);
    }

    /**
     * Group yang dipakai voucher tapi belum punya policy, dan router hotspot yang
     * belum terdaftar di tabel nas. Keduanya nyata merugikan — login tanpa batas,
     * dan Access-Request yang diabaikan FreeRADIUS — tapi skemanya sendiri sah,
     * jadi keduanya catatan, bukan kegagalan.
     *
     * Voucher di bawah juga membuktikan yang diperiksa bukan cuma group default:
     * setiap profile yang dipakai voucher ikut dicari policy-nya.
     */
    public function test_a_profile_without_policy_and_an_unregistered_router_only_warn(): void
    {
        $this->seedRadiusGroup('mahasiswa');

        HotspotVoucher::create([
            'nim' => '2101001',
            'password' => '30051988',
            'profile' => 'paket-dosen',
            'router_host' => self::ROUTER,
            'source' => HotspotVoucher::SOURCE_MANUAL,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('paket-dosen')
            ->expectsOutputToContain('POLICY KOSONG')
            ->expectsOutputToContain('BELUM TERDAFTAR')
            ->expectsOutputToContain('Skema siap dipakai')
            ->assertExitCode(0);
    }

    /** 0.0.0.0/0 di tabel nas menerima NAS mana pun — lebih longgar, tapi sah. */
    public function test_a_wildcard_nas_row_counts_as_the_router_being_registered(): void
    {
        $this->seedRadiusGroup('mahasiswa');
        $this->registerNas('0.0.0.0/0');

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('tercakup 0.0.0.0/0')
            ->expectsOutputToContain('Semua pemeriksaan lolos')
            ->assertExitCode(0);
    }

    /**
     * Tabel daloRADIUS berarti ada UI lain yang bisa mengubah baris yang sama
     * dengan CIMS. Itu perlu diketahui operator, tapi skemanya tetap sah — jadi
     * dilaporkan sebagai catatan, bukan penolakan.
     */
    public function test_a_daloradius_database_is_flagged_without_failing(): void
    {
        Schema::connection($this->radiusConnection)->create('userinfo', function (Blueprint $t) {
            $t->increments('id');
            $t->string('username', 64)->default('');
        });

        $this->seedRadiusGroup('mahasiswa');
        $this->registerNas(self::ROUTER);

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('daloRADIUS')
            ->expectsOutputToContain('Skema siap dipakai')
            ->assertExitCode(0);
    }

    /** Tanpa RADIUS_DB_* tidak ada gunanya mencoba menyambung; yang dicetak blok .env-nya. */
    public function test_it_stops_before_connecting_when_the_env_is_incomplete(): void
    {
        config(['database.connections.' . $this->radiusConnection . '.database' => null]);

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('RADIUS_DB_DATABASE')
            ->expectsOutputToContain('config:clear')
            ->assertExitCode(1);
    }

    /**
     * Koneksi yang gagal dari server lain hampir selalu bind-address, GRANT, atau
     * firewall — ketiganya dibereskan di server RADIUS, bukan di kode CIMS. Itulah
     * yang harus terbaca operator, bukan cuma pesan PDO.
     */
    public function test_an_unreachable_server_prints_the_three_usual_causes(): void
    {
        $this->breakRadiusConnection();

        $this->artisan('radius:doctor')
            ->expectsOutputToContain('Tidak bisa tersambung')
            ->expectsOutputToContain('bind-address')
            ->expectsOutputToContain('3306')
            ->assertExitCode(1);
    }

    /** Router hotspot yang terdaftar di tabel nas. Kolom secret tidak pernah disentuh. */
    private function registerNas(string $nasname): void
    {
        $this->radiusDb()->table('nas')->insert([
            'nasname' => $nasname,
            'shortname' => 'hotspot',
            'type' => 'other',
        ]);
    }
}
