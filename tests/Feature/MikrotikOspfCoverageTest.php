<?php

namespace Tests\Feature;

use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Interface mana yang sudah routing OSPF, dan mana yang belum" tidak dijawab
 * oleh satu perintah RouterOS pun — kesimpulannya harus dirakit dari empat
 * daftar terpisah: interface, alamat IP, aturan OSPF, dan tabel neighbor.
 *
 * Perakitan itulah yang diuji di sini, memakai baris mentah seperti yang
 * benar-benar dikirim RouterOS, supaya klasifikasinya tidak bergeser tanpa
 * ketahuan. Yang paling mudah rusak dan paling mahal akibatnya ada dua: hanya
 * state "Full" yang berarti routing benar-benar bertukar, dan interface tanpa
 * alamat IP tidak boleh ikut terhitung sebagai pekerjaan yang tertunda — di
 * router kampus jumlahnya jauh lebih banyak daripada interface routed.
 *
 * Tidak ada router yang dihubungi: buildOspfCoverage() sengaja bebas I/O.
 */
class MikrotikOspfCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Panggil buildOspfCoverage() langsung. Method-nya protected dan memang
     * tidak untuk dipakai dari luar service — yang publik adalah
     * getOspfCoverage(), yang ikut menghubungi router.
     */
    private function build(
        array $interfaces,
        array $addresses,
        array $ospfInterfaces = [],
        array $neighbors = [],
        array $ruleSet = ['flavor' => 'v7', 'rules' => []],
        array $instances = [['name' => 'default', 'router-id' => '10.255.255.1', 'disabled' => 'false']],
        array $areas = [['name' => 'backbone', 'area-id' => '0.0.0.0', 'instance' => 'default']]
    ): array {
        $method = new \ReflectionMethod(MikrotikService::class, 'buildOspfCoverage');

        return $method->invoke(
            app(MikrotikService::class),
            $interfaces,
            $addresses,
            $instances,
            $areas,
            $ospfInterfaces,
            $neighbors,
            $ruleSet
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function byInterface(array $coverage): array
    {
        $indexed = [];

        foreach ($coverage['interfaces'] as $row) {
            $indexed[$row['interface']] = $row;
        }

        return $indexed;
    }

    private function iface(string $name, string $type = 'ether', bool $running = true, bool $disabled = false): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'running' => $running ? 'true' : 'false',
            'disabled' => $disabled ? 'true' : 'false',
        ];
    }

    private function address(string $address, string $interface): array
    {
        return ['address' => $address, 'interface' => $interface, 'disabled' => 'false'];
    }

    /**
     * Satu router inti kampus yang realistis, gaya RouterOS v7: uplink ISP di
     * luar OSPF, dua link transit ke distribusi, satu bridge pengguna yang
     * passive, satu port cadangan tanpa IP, dan satu interface yang tercakup
     * konfigurasi tetapi tidak pernah menjadi entri OSPF efektif.
     */
    private function v7Campus(): array
    {
        return $this->build(
            interfaces: [
                $this->iface('ether1'),
                $this->iface('ether2'),
                $this->iface('ether3'),
                $this->iface('ether4'),
                $this->iface('ether5'),
                $this->iface('ether6'),
                $this->iface('bridge-lan', type: 'bridge'),
            ],
            addresses: [
                $this->address('203.0.113.1/30', 'ether1'),
                $this->address('10.10.0.1/30', 'ether2'),
                $this->address('10.10.0.5/30', 'ether3'),
                $this->address('10.30.0.1/24', 'ether5'),
                $this->address('10.10.0.9/30', 'ether6'),
                $this->address('10.20.0.1/24', 'bridge-lan'),
            ],
            ospfInterfaces: [
                ['interface' => 'ether2', 'instance' => 'default', 'area' => 'backbone', 'cost' => '10', 'network-type' => 'ptp', 'passive' => 'false', 'dynamic' => 'true', 'disabled' => 'false'],
                ['interface' => 'ether3', 'instance' => 'default', 'area' => 'backbone', 'cost' => '10', 'passive' => 'false', 'dynamic' => 'true', 'disabled' => 'false'],
                ['interface' => 'ether6', 'instance' => 'default', 'area' => 'backbone', 'passive' => 'false', 'dynamic' => 'true', 'disabled' => 'false'],
                ['interface' => 'bridge-lan', 'instance' => 'default', 'area' => 'backbone', 'passive' => 'true', 'dynamic' => 'true', 'disabled' => 'false'],
            ],
            neighbors: [
                ['instance' => 'default', 'area' => 'backbone', 'router-id' => '10.255.255.2', 'address' => '10.10.0.2', 'interface' => 'ether2', 'state' => 'Full', 'state-changes' => '4', 'adjacency' => '2d3h'],
                ['instance' => 'default', 'area' => 'backbone', 'router-id' => '10.255.255.3', 'address' => '10.10.0.10', 'interface' => 'ether6', 'state' => '2-Way', 'state-changes' => '12', 'adjacency' => '1m'],
            ],
            ruleSet: ['flavor' => 'v7', 'rules' => [[
                'interfaces' => null,
                'networks' => '10.10.0.0/16,10.20.0.0/16,10.30.0.0/16',
                'area' => 'backbone',
                'cost' => null,
                'passive' => false,
                'disabled' => false,
                'comment' => null,
            ]]],
        );
    }

    public function test_it_separates_interfaces_already_routing_ospf_from_those_that_are_not(): void
    {
        $rows = $this->byInterface($this->v7Campus());

        // Uplink ISP: punya IP, tidak dicakup prefix OSPF mana pun. Inilah bentuk
        // "belum routing OSPF" yang sebenarnya dicari operator.
        $this->assertSame('not_in_ospf', $rows['ether1']['status']);
        $this->assertFalse($rows['ether1']['in_ospf']);

        // Link transit yang sehat.
        $this->assertSame('full', $rows['ether2']['status']);
        $this->assertTrue($rows['ether2']['in_ospf']);
        $this->assertSame(1, $rows['ether2']['full_neighbors']);
        $this->assertSame('backbone', $rows['ether2']['area']);
        $this->assertSame('10', $rows['ether2']['cost']);
    }

    public function test_it_counts_each_status_for_the_summary_cards(): void
    {
        $summary = $this->v7Campus()['summary'];

        $this->assertSame(7, $summary['total']);
        $this->assertSame(4, $summary['in_ospf']);
        $this->assertSame(1, $summary['full']);
        $this->assertSame(1, $summary['passive']);
        $this->assertSame(1, $summary['not_in_ospf']);
        $this->assertSame(1, $summary['no_ip']);
        // ether3 (tanpa neighbor), ether5 (tercakup tapi tidak efektif), ether6
        // (neighbor belum Full).
        $this->assertSame(3, $summary['warning']);
        $this->assertSame(2, $summary['neighbors']);
        $this->assertSame(1, $summary['full_neighbors']);
    }

    /**
     * Router kampus punya jauh lebih banyak port cadangan, bridge port, dan VLAN
     * tanpa IP daripada interface routed. Kalau semuanya masuk daftar "belum
     * OSPF", daftar itu kehilangan seluruh gunanya.
     */
    public function test_an_interface_without_an_ip_is_not_pending_ospf_work(): void
    {
        $rows = $this->byInterface($this->v7Campus());

        $this->assertSame('no_ip', $rows['ether4']['status']);
        $this->assertNotSame('not_in_ospf', $rows['ether4']['status']);
    }

    /**
     * Neighbor yang terlihat tapi tidak Full adalah kegagalan OSPF yang paling
     * sering terjadi dan paling sering luput: routing tidak bertukar sama sekali,
     * padahal tabel neighbor tampak berisi.
     */
    public function test_a_neighbor_that_is_not_full_is_reported_as_a_problem(): void
    {
        $rows = $this->byInterface($this->v7Campus());

        $this->assertSame('warning', $rows['ether6']['status']);
        $this->assertSame(1, $rows['ether6']['neighbor_count']);
        $this->assertSame(0, $rows['ether6']['full_neighbors']);
        $this->assertStringContainsString('belum Full', $rows['ether6']['detail']);
        $this->assertFalse($rows['ether6']['neighbors'][0]['full']);
    }

    public function test_an_interface_in_ospf_without_any_neighbor_is_flagged(): void
    {
        $rows = $this->byInterface($this->v7Campus());

        $this->assertSame('warning', $rows['ether3']['status']);
        $this->assertSame(0, $rows['ether3']['neighbor_count']);
        $this->assertStringContainsString('belum ada neighbor', $rows['ether3']['detail']);
    }

    /**
     * Passive memang tidak pernah membentuk adjacency — itu tujuannya. Menuntut
     * neighbor di sini akan membanjiri layar dengan peringatan palsu untuk setiap
     * bridge pengguna.
     */
    public function test_a_passive_interface_is_not_blamed_for_having_no_neighbor(): void
    {
        $rows = $this->byInterface($this->v7Campus());

        $this->assertSame('passive', $rows['bridge-lan']['status']);
        $this->assertTrue($rows['bridge-lan']['passive']);
        $this->assertSame(0, $rows['bridge-lan']['neighbor_count']);
    }

    /**
     * ether5 ditulis di konfigurasi (tercakup prefix 10.30.0.0/16) tetapi tidak
     * pernah muncul sebagai entri OSPF efektif — hampir selalu berarti instance
     * atau template-nya disabled. Kalau digabung dengan "belum OSPF", operator
     * akan mencari-cari konfigurasi yang sebenarnya sudah ada.
     */
    public function test_config_that_covers_an_interface_but_never_takes_effect_is_flagged(): void
    {
        $rows = $this->byInterface($this->v7Campus());

        $this->assertSame('warning', $rows['ether5']['status']);
        $this->assertFalse($rows['ether5']['in_ospf']);
        $this->assertSame('prefix 10.30.0.0/16', $rows['ether5']['matched_by']);
        $this->assertStringContainsString('disabled', $rows['ether5']['detail']);
    }

    /**
     * Yang perlu ditindaklanjuti harus terbaca tanpa scroll: router kampus punya
     * puluhan interface, dan satu adjacency yang gagal tidak boleh mengendap di
     * bawah daftar port cadangan.
     */
    public function test_rows_needing_attention_are_listed_first(): void
    {
        $order = array_column($this->v7Campus()['interfaces'], 'status');

        $this->assertSame([
            'warning',      // ether3
            'warning',      // ether5
            'warning',      // ether6
            'not_in_ospf',  // ether1
            'passive',      // bridge-lan
            'full',         // ether2
            'no_ip',        // ether4
        ], $order);
    }

    /**
     * RouterOS v6 memakai /routing/ospf/network yang hanya mengenal prefix, bukan
     * nama interface. Cakupannya karena itu harus dicocokkan lewat alamat IP
     * interface — jalur pencocokan yang berbeda dari v7 interfaces=.
     */
    public function test_it_reads_v6_network_statements_as_well_as_v7_templates(): void
    {
        $coverage = $this->build(
            interfaces: [$this->iface('ether1'), $this->iface('ether2')],
            addresses: [
                $this->address('10.0.0.1/24', 'ether1'),
                $this->address('192.168.88.1/24', 'ether2'),
            ],
            ospfInterfaces: [
                ['interface' => 'ether1', 'instance' => 'default', 'area' => 'backbone', 'passive' => 'false', 'disabled' => 'false'],
            ],
            neighbors: [
                ['router-id' => '10.255.255.9', 'address' => '10.0.0.9', 'interface' => 'ether1', 'state' => 'Full'],
            ],
            ruleSet: ['flavor' => 'v6', 'rules' => [[
                'interfaces' => null,
                'networks' => '10.0.0.0/8',
                'area' => 'backbone',
                'cost' => null,
                'passive' => false,
                'disabled' => false,
                'comment' => null,
            ]]],
        );

        $rows = $this->byInterface($coverage);

        $this->assertSame('v6', $coverage['flavor']);
        $this->assertSame('full', $rows['ether1']['status']);
        $this->assertSame('prefix 10.0.0.0/8', $rows['ether1']['matched_by']);
        $this->assertSame('not_in_ospf', $rows['ether2']['status']);
    }

    /**
     * Template `interfaces=all` adalah bentuk paling umum di RouterOS v7. Kalau
     * kata kunci itu diperlakukan sebagai cakupan untuk semua interface tanpa
     * kecuali, setiap port kosong akan tampil sebagai "sudah dikonfigurasi tapi
     * bermasalah" — padahal OSPFv2 tidak bisa berjalan tanpa alamat IPv4.
     */
    public function test_the_wildcard_template_does_not_sweep_in_interfaces_without_an_ip(): void
    {
        $rows = $this->byInterface($this->build(
            interfaces: [$this->iface('ether1'), $this->iface('ether9')],
            addresses: [$this->address('172.16.0.1/24', 'ether1')],
            ruleSet: ['flavor' => 'v7', 'rules' => [[
                'interfaces' => 'all',
                'networks' => null,
                'area' => 'backbone',
                'cost' => null,
                'passive' => false,
                'disabled' => false,
                'comment' => null,
            ]]],
        ));

        // Punya IP, tercakup wildcard, tetapi bukan entri OSPF efektif.
        $this->assertSame('warning', $rows['ether1']['status']);
        $this->assertSame('template interfaces=all', $rows['ether1']['matched_by']);

        // Port kosong tetap di luar cakupan, wildcard atau bukan. Aturannya masih
        // dilaporkan sebagai asal cakupan — berguna untuk menjelaskan bahwa port ini
        // akan ikut OSPF begitu diberi alamat — tetapi statusnya, yang menentukan
        // masuk-tidaknya ke daftar pekerjaan, tetap di luar cakupan.
        $this->assertSame('no_ip', $rows['ether9']['status']);
        $this->assertStringContainsString('tidak bisa ikut OSPF', $rows['ether9']['detail']);
    }

    /**
     * Aturan yang disabled bukan cakupan. Membacanya sebagai cakupan akan menutupi
     * interface yang sesungguhnya belum masuk OSPF.
     */
    public function test_a_disabled_rule_does_not_count_as_coverage(): void
    {
        $rows = $this->byInterface($this->build(
            interfaces: [$this->iface('ether1')],
            addresses: [$this->address('10.50.0.1/24', 'ether1')],
            ruleSet: ['flavor' => 'v7', 'rules' => [[
                'interfaces' => 'ether1',
                'networks' => null,
                'area' => 'backbone',
                'cost' => null,
                'passive' => false,
                'disabled' => true,
                'comment' => null,
            ]]],
        ));

        $this->assertSame('not_in_ospf', $rows['ether1']['status']);
        $this->assertNull($rows['ether1']['matched_by']);
    }

    /**
     * Alamat yang disabled tidak akan pernah dijalankan OSPF, jadi tidak boleh
     * membuat interface tampak siap ikut OSPF.
     */
    public function test_a_disabled_ip_address_is_not_treated_as_an_address(): void
    {
        $rows = $this->byInterface($this->build(
            interfaces: [$this->iface('ether1')],
            addresses: [['address' => '10.60.0.1/24', 'interface' => 'ether1', 'disabled' => 'true']],
        ));

        $this->assertSame('no_ip', $rows['ether1']['status']);
        $this->assertSame([], $rows['ether1']['addresses']);
    }

    public function test_a_disabled_ospf_entry_is_flagged_rather_than_reported_as_active(): void
    {
        $rows = $this->byInterface($this->build(
            interfaces: [$this->iface('ether1')],
            addresses: [$this->address('10.70.0.1/30', 'ether1')],
            ospfInterfaces: [
                ['interface' => 'ether1', 'instance' => 'default', 'area' => 'backbone', 'passive' => 'false', 'disabled' => 'true'],
            ],
        ));

        $this->assertSame('warning', $rows['ether1']['status']);
        $this->assertStringContainsString('disabled', $rows['ether1']['detail']);
    }

    /**
     * Interface mati yang masuk OSPF tidak akan pernah membentuk adjacency. Sebabnya
     * ada di lapisan fisik, bukan di konfigurasi OSPF — dan pesannya harus
     * mengarahkan ke sana, bukan ke MTU atau area.
     */
    public function test_an_interface_that_is_not_running_names_the_link_as_the_cause(): void
    {
        $rows = $this->byInterface($this->build(
            interfaces: [$this->iface('ether1', running: false)],
            addresses: [$this->address('10.80.0.1/30', 'ether1')],
            ospfInterfaces: [
                ['interface' => 'ether1', 'instance' => 'default', 'area' => 'backbone', 'passive' => 'false', 'disabled' => 'false'],
            ],
        ));

        $this->assertSame('warning', $rows['ether1']['status']);
        $this->assertStringContainsString('tidak running', $rows['ether1']['detail']);
    }

    /**
     * Seluruh pencocokan prefix bergantung pada pemeriksaan ini. Salah satu bit
     * saja pada mask berarti interface yang salah dinyatakan sudah tercakup OSPF.
     *
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    public static function cidrCases(): array
    {
        return [
            'di dalam /16' => ['10.10.0.5', '10.10.0.0/16', true],
            'di luar /16' => ['10.11.0.5', '10.10.0.0/16', false],
            'batas bawah /30' => ['10.10.0.4', '10.10.0.4/30', true],
            'lewat batas /30' => ['10.10.0.8', '10.10.0.4/30', false],
            'host /32 tepat' => ['10.0.0.1', '10.0.0.1/32', true],
            'host /32 beda' => ['10.0.0.2', '10.0.0.1/32', false],
            'default route mencakup semua' => ['192.168.88.1', '0.0.0.0/0', true],
            'tanpa panjang prefix' => ['10.0.0.1', '10.0.0.0', false],
            'panjang prefix bukan angka' => ['10.0.0.1', '10.0.0.0/dua', false],
            'panjang prefix di atas 32' => ['10.0.0.1', '10.0.0.0/33', false],
            'alamat tidak valid' => ['bukan-ip', '10.0.0.0/8', false],
        ];
    }

    #[DataProvider('cidrCases')]
    public function test_the_cidr_check_matches_only_addresses_inside_the_prefix(string $ip, string $cidr, bool $expected): void
    {
        $method = new \ReflectionMethod(MikrotikService::class, 'ipv4InCidr');

        $this->assertSame($expected, $method->invoke(app(MikrotikService::class), $ip, $cidr));
    }

    /**
     * Router tidak terjangkau harus menghasilkan payload berbentuk sama dengan
     * hasil normal — hanya kosong dan bertanda `reachable: false`. Kalau bentuknya
     * berbeda, tab OSPF harus menangani dua struktur data, dan "router mati" akan
     * tampil sama saja seperti "tidak ada interface yang ikut OSPF".
     *
     * Loopback port 9: koneksi ditolak seketika, tidak ada trafik keluar.
     */
    public function test_an_unreachable_router_reports_the_failure_instead_of_an_empty_map(): void
    {
        config([
            'services.mikrotik.host' => '127.0.0.1',
            'services.mikrotik.port' => 9,
            'services.mikrotik.user' => 'user-global-env',
            'services.mikrotik.password' => 'rahasia',
            'services.mikrotik.attempts' => 1,
            'services.mikrotik.timeout' => 1,
        ]);

        $coverage = app(MikrotikService::class)->getOspfCoverage('127.0.0.1');

        $this->assertFalse($coverage['reachable']);
        $this->assertNotNull($coverage['error']);
        $this->assertSame([], $coverage['interfaces']);
        $this->assertSame([], $coverage['neighbors']);
        $this->assertFalse($coverage['configured']);
        $this->assertSame(0, $coverage['summary']['total']);
        $this->assertArrayHasKey('not_in_ospf', $coverage['summary']);
    }

    /**
     * Router yang menjawab tetapi belum punya instance OSPF sama sekali bukan
     * kegagalan: `configured: false` supaya UI bisa mengatakan "OSPF belum
     * dijalankan" alih-alih menampilkan tabel kosong tanpa keterangan.
     */
    public function test_a_router_without_any_ospf_instance_is_reported_as_not_configured(): void
    {
        $coverage = $this->build(
            interfaces: [$this->iface('ether1')],
            addresses: [$this->address('10.90.0.1/24', 'ether1')],
            instances: [],
            areas: [],
        );

        $this->assertTrue($coverage['reachable']);
        $this->assertTrue($coverage['supported']);
        $this->assertFalse($coverage['configured']);
        $this->assertSame('not_in_ospf', $coverage['interfaces'][0]['status']);
    }
}
