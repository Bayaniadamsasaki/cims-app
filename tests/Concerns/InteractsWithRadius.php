<?php

namespace Tests\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database FreeRADIUS tiruan untuk test, dibangun di sqlite :memory:.
 *
 * Skema RADIUS bukan hasil migrasi CIMS — ia milik FreeRADIUS — jadi tidak ada
 * file migrasi yang bisa dipakai ulang di sini. Bentuk tabelnya ditiru dari
 * schema.sql FreeRADIUS 3.2.x apa adanya, termasuk dua hal yang justru menjadi
 * inti beberapa test:
 *
 *   1. `radusergroup` memang TIDAK punya primary key maupun unique. Itu alasan
 *      RadiusService memakai pola hapus-lalu-tulis, bukan upsert per baris, dan
 *      test idempotensi hanya bermakna kalau tabel tiruannya juga tanpa kunci.
 *   2. `op` adalah char(2) dan nilainya penting: ':=' berarti "ganti nilainya",
 *      '=' cuma menambahkan bila atributnya belum ada.
 *
 * RefreshDatabase hanya mengurus connection default, jadi tabel RADIUS dibuat
 * ulang di setiap test lewat setUpRadiusDatabase(). Tanpa itu isi satu test
 * bocor ke test berikutnya: PDO sqlite :memory: hidup sepanjang proses.
 */
trait InteractsWithRadius
{
    /** Nama connection RADIUS untuk test — sqlite :memory: lewat phpunit.xml. */
    protected string $radiusConnection = 'radius';

    /**
     * Bangun ulang tabel RADIUS yang kosong. Panggil dari setUp().
     */
    protected function setUpRadiusDatabase(): void
    {
        $schema = Schema::connection($this->radiusConnection);

        foreach (['radcheck', 'radreply', 'radusergroup', 'radgroupcheck', 'radgroupreply', 'radacct', 'radpostauth', 'nas'] as $table) {
            $schema->dropIfExists($table);
        }

        foreach (['radcheck' => 'username', 'radreply' => 'username', 'radgroupcheck' => 'groupname', 'radgroupreply' => 'groupname'] as $table => $subject) {
            $schema->create($table, function (Blueprint $t) use ($subject) {
                $t->increments('id');
                $t->string($subject, 64)->default('');
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default('==');
                $t->string('value', 253)->default('');
                $t->index($subject);
            });
        }

        // Sengaja tanpa primary key, seperti skema aslinya.
        $schema->create('radusergroup', function (Blueprint $t) {
            $t->string('username', 64)->default('');
            $t->string('groupname', 64)->default('');
            $t->integer('priority')->default(1);
            $t->index('username');
        });

        $schema->create('radacct', function (Blueprint $t) {
            $t->bigIncrements('radacctid');
            $t->string('acctsessionid', 64)->default('');
            $t->string('acctuniqueid', 32)->default('');
            $t->string('username', 64)->default('');
            $t->string('nasipaddress', 45)->default('');
            $t->dateTime('acctstarttime')->nullable();
            $t->dateTime('acctstoptime')->nullable();
            $t->integer('acctsessiontime')->nullable();
            $t->bigInteger('acctinputoctets')->nullable();
            $t->bigInteger('acctoutputoctets')->nullable();
            $t->string('callingstationid', 50)->default('');
        });

        $schema->create('radpostauth', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('username', 64);
            $t->string('pass', 64)->default('');
            $t->string('reply', 32)->default('');
            $t->dateTime('authdate')->nullable();
        });

        $schema->create('nas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nasname', 128);
            $t->string('shortname', 32)->nullable();
            $t->string('type', 30)->default('other');
            $t->integer('ports')->nullable();
            $t->string('secret', 60)->default('secret');
            $t->string('server', 64)->nullable();
            $t->string('community', 50)->nullable();
            $t->string('description', 200)->nullable();
        });
    }

    protected function radiusDb(): ConnectionInterface
    {
        return DB::connection($this->radiusConnection);
    }

    /**
     * Atribut radcheck satu username sebagai attribute => value.
     *
     * @return array<string,string>
     */
    protected function radiusCheck(string $username): array
    {
        return $this->radiusDb()->table('radcheck')
            ->where('username', $username)
            ->pluck('value', 'attribute')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /**
     * Atribut radreply satu username sebagai attribute => value.
     *
     * @return array<string,string>
     */
    protected function radiusReply(string $username): array
    {
        return $this->radiusDb()->table('radreply')
            ->where('username', $username)
            ->pluck('value', 'attribute')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /**
     * Group yang diikuti satu username, urut sesuai isi tabel.
     *
     * @return array<int,string>
     */
    protected function radiusGroupsOf(string $username): array
    {
        return $this->radiusDb()->table('radusergroup')
            ->where('username', $username)
            ->pluck('groupname')
            ->map(fn ($group) => (string) $group)
            ->all();
    }

    /** Policy paket di radgroupreply, seperti yang dibuat operator RADIUS. */
    protected function seedRadiusGroup(string $groupname, string $rateLimit = '2M/2M'): void
    {
        $this->radiusDb()->table('radgroupreply')->insert([
            'groupname' => $groupname,
            'attribute' => 'Mikrotik-Rate-Limit',
            'op' => ':=',
            'value' => $rateLimit,
        ]);
    }

    /**
     * Baris RADIUS milik konfigurasi lain — bukan CIMS.
     *
     * Dititipkan di test untuk membuktikan CIMS tidak pernah menghapus atribut di
     * luar MANAGED_CHECK/MANAGED_REPLY. Ini pengaman yang paling mahal kalau
     * hilang: database RADIUS bisa dipakai layanan lain (VPN, PPPoE), dan DELETE
     * yang terlalu luas akan memutus layanan yang tidak ada hubungannya dengan
     * voucher hotspot.
     */
    protected function seedForeignRadiusRows(string $username): void
    {
        $this->radiusDb()->table('radcheck')->insert([
            ['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => '31 Dec 2030'],
            ['username' => $username, 'attribute' => 'Simultaneous-Use', 'op' => ':=', 'value' => '3'],
        ]);

        $this->radiusDb()->table('radreply')->insert([
            ['username' => $username, 'attribute' => 'Framed-IP-Address', 'op' => ':=', 'value' => '10.10.10.10'],
        ]);
    }

    /**
     * Buat RADIUS tidak bisa dihubungi, tanpa menyentuh server sungguhan.
     *
     * Databasenya diarahkan ke berkas sqlite di direktori yang tidak ada, jadi
     * PDO gagal saat connect — persis seperti server RADIUS yang mati. Nilai
     * host/database/username tetap terisi supaya configured() tetap true dan
     * yang teruji benar-benar health(), bukan pengecekan .env.
     */
    protected function breakRadiusConnection(): void
    {
        config(['database.connections.'.$this->radiusConnection.'.driver' => 'sqlite']);
        config(['database.connections.'.$this->radiusConnection.'.database' => __DIR__.'/tidak-ada/radius.sqlite']);

        DB::purge($this->radiusConnection);
    }
}
