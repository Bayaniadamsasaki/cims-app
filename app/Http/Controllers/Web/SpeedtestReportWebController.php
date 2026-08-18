<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Speedtest\StoreSpeedtestReportRequest;
use App\Http\Requests\Speedtest\UpdateSpeedtestReportRequest;
use App\Models\SpeedtestReport;
use App\Models\SpeedtestTester;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SpeedtestReportWebController extends Controller
{
    /**
     * Kolom yang boleh dipakai untuk sorting tabel.
     */
    private const SORTABLE = ['tested_at', 'download_mbps', 'upload_mbps', 'ping_ms'];

    /**
     * Pilihan jumlah data per halaman.
     */
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * Halaman utama Laporan Speedtest Jaringan Bulanan.
     */
    public function index(Request $request): Response
    {
        $filters = $this->resolveFilters($request);

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort')
            : 'tested_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE_OPTIONS, true)
            ? (int) $request->query('per_page')
            : 10;

        $reports = SpeedtestReport::with('tester')
            ->filter($filters)
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('SpeedtestReports/Index', [
            'reports' => $reports,
            'summary' => $this->buildSummary($filters),
            'trend' => $this->buildTrend($filters),
            'testers' => SpeedtestTester::orderBy('name')->withCount('reports')->get(),
            'locationOptions' => SpeedtestReport::query()->distinct()->orderBy('location')->pluck('location'),
            'ssidOptions' => SpeedtestReport::query()->distinct()->orderBy('ssid')->pluck('ssid'),
            'options' => [
                'statuses' => SpeedtestReport::STATUSES,
                'deviceTypes' => SpeedtestReport::DEVICE_TYPES,
                'actions' => SpeedtestReport::ACTIONS,
                'perPage' => self::PER_PAGE_OPTIONS,
            ],
            'filters' => $filters,
            'sort' => ['column' => $sort, 'direction' => $direction],
            'perPage' => $perPage,
        ]);
    }

    /**
     * Simpan laporan speedtest baru.
     */
    public function store(StoreSpeedtestReportRequest $request)
    {
        $data = $request->safe()->except('screenshot');
        $data['created_by'] = $request->user()?->id;

        if ($request->hasFile('screenshot')) {
            $data['screenshot_path'] = $request->file('screenshot')->store('speedtest-screenshots', 'public');
        }

        SpeedtestReport::create($data);

        return redirect()->back()->with('success', 'Laporan speedtest berhasil ditambahkan.');
    }

    /**
     * Perbarui laporan speedtest.
     * Dikirim via POST agar upload multipart/form-data tetap terbaca Laravel.
     */
    public function update(UpdateSpeedtestReportRequest $request, int $id)
    {
        $report = SpeedtestReport::findOrFail($id);
        $data = $request->safe()->except(['screenshot', 'remove_screenshot']);

        $replaceScreenshot = $request->hasFile('screenshot');
        $removeScreenshot = $request->boolean('remove_screenshot');

        if (($replaceScreenshot || $removeScreenshot) && $report->screenshot_path) {
            Storage::disk('public')->delete($report->screenshot_path);
            $data['screenshot_path'] = null;
        }

        if ($replaceScreenshot) {
            $data['screenshot_path'] = $request->file('screenshot')->store('speedtest-screenshots', 'public');
        }

        $report->update($data);

        return redirect()->back()->with('success', 'Laporan speedtest berhasil diperbarui.');
    }

    /**
     * Hapus laporan speedtest beserta bukti screenshot-nya.
     */
    public function destroy(int $id)
    {
        $report = SpeedtestReport::findOrFail($id);

        if ($report->screenshot_path) {
            Storage::disk('public')->delete($report->screenshot_path);
        }

        $report->delete();

        return redirect()->back()->with('success', 'Laporan speedtest berhasil dihapus.');
    }

    /**
     * Tambah nama penguji baru (dropdown Penguji bersifat dinamis).
     */
    public function storeTester(Request $request)
    {
        $request->validate(
            ['name' => ['required', 'string', 'max:255', 'unique:speedtest_testers,name']],
            [
                'name.required' => 'Nama penguji wajib diisi.',
                'name.unique' => 'Nama penguji tersebut sudah terdaftar.',
            ]
        );

        SpeedtestTester::create(['name' => $request->string('name')->trim()->value()]);

        return redirect()->back()->with('success', 'Penguji baru berhasil ditambahkan.');
    }

    /**
     * Hapus penguji. Ditolak apabila masih dipakai oleh laporan yang ada.
     */
    public function destroyTester(int $id)
    {
        $tester = SpeedtestTester::findOrFail($id);

        if ($tester->reports()->exists()) {
            return redirect()->back()->with('error', "Penguji \"{$tester->name}\" tidak dapat dihapus karena masih dipakai oleh laporan speedtest.");
        }

        try {
            $tester->delete();
        } catch (QueryException) {
            return redirect()->back()->with('error', "Penguji \"{$tester->name}\" tidak dapat dihapus karena masih dipakai oleh laporan speedtest.");
        }

        return redirect()->back()->with('success', 'Penguji berhasil dihapus.');
    }

    /**
     * Export data laporan (mengikuti filter aktif) ke CSV.
     */
    public function exportCsv(Request $request)
    {
        $filters = $this->resolveFilters($request);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="laporan_speedtest_'.date('Ymd_His').'.csv"',
        ];

        $callback = function () use ($filters) {
            $file = fopen('php://output', 'w');
            // BOM agar karakter non-ASCII terbaca benar di Excel.
            fwrite($file, "\xEF\xBB\xBF");

            fputcsv($file, [
                'No', 'Tanggal Action', 'Lokasi', 'SSID', 'Download (Mbps)', 'Upload (Mbps)',
                'Ping (ms)', 'Status Action', 'Perangkat Uji Coba', 'Penguji', 'Tindakan', 'Bukti Screenshot',
            ]);

            $no = 0;
            SpeedtestReport::with('tester')
                ->filter($filters)
                ->orderBy('tested_at')
                ->chunk(500, function ($rows) use ($file, &$no) {
                    foreach ($rows as $report) {
                        fputcsv($file, [
                            ++$no,
                            $report->tested_at->format('Y-m-d H:i'),
                            $report->location,
                            $report->ssid,
                            number_format($report->download_mbps, 2, '.', ''),
                            number_format($report->upload_mbps, 2, '.', ''),
                            number_format($report->ping_ms, 2, '.', ''),
                            $report->status_label,
                            $report->device_type_label,
                            $report->tester?->name ?? '-',
                            $report->action_label,
                            $report->screenshot_url ?? '-',
                        ]);
                    }
                });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Ambil filter yang didukung dari query string.
     *
     * @return array<string, string|null>
     */
    private function resolveFilters(Request $request): array
    {
        $filters = $request->only([
            'search', 'month', 'date_from', 'date_to', 'location', 'ssid', 'status', 'tester_id', 'action',
        ]);

        // Buang nilai kosong supaya scopeFilter() tidak ikut memfilter string kosong.
        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Summary card dihitung dari seluruh data yang lolos filter, bukan hanya halaman aktif.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    private function buildSummary(array $filters): array
    {
        $aggregate = SpeedtestReport::filter($filters)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(download_mbps) as avg_download')
            ->selectRaw('AVG(upload_mbps) as avg_upload')
            ->selectRaw('AVG(ping_ms) as avg_ping')
            ->first();

        $byStatus = SpeedtestReport::filter($filters)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) ($aggregate->total ?? 0),
            'avgDownload' => round((float) ($aggregate->avg_download ?? 0), 2),
            'avgUpload' => round((float) ($aggregate->avg_upload ?? 0), 2),
            'avgPing' => round((float) ($aggregate->avg_ping ?? 0), 2),
            'statusCounts' => collect(array_keys(SpeedtestReport::STATUSES))
                ->mapWithKeys(fn (string $key) => [$key => (int) ($byStatus[$key] ?? 0)])
                ->all(),
        ];
    }

    /**
     * Data tren harian untuk grafik (rata-rata per tanggal pengujian).
     *
     * @param  array<string, string|null>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildTrend(array $filters): array
    {
        return SpeedtestReport::filter($filters)
            ->selectRaw('DATE(tested_at) as date')
            ->selectRaw('AVG(download_mbps) as avg_download')
            ->selectRaw('AVG(upload_mbps) as avg_upload')
            ->selectRaw('AVG(ping_ms) as avg_ping')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'avgDownload' => round((float) $row->avg_download, 2),
                'avgUpload' => round((float) $row->avg_upload, 2),
                'avgPing' => round((float) $row->avg_ping, 2),
                'total' => (int) $row->total,
            ])
            ->all();
    }
}
