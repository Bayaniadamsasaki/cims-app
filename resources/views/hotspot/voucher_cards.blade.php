{{-- Kartu voucher hotspot mahasiswa — dirender dompdf (A4 portrait, 2 kolom × 5 baris). --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Voucher Hotspot Mahasiswa</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #0f172a; }
        .page-title { font-size: 12px; font-weight: bold; margin: 0 0 2mm; }
        .page-sub { font-size: 8px; color: #64748b; margin: 0 0 4mm; }
        table.grid { width: 100%; border-collapse: separate; border-spacing: 3mm 3mm; }
        table.grid td { width: 50%; vertical-align: top; }
        .card {
            border: 1px dashed #94a3b8;
            border-radius: 3mm;
            padding: 3mm;
            height: 42mm;
        }
        .card-head { font-size: 8px; color: #2563eb; font-weight: bold; text-transform: uppercase; }
        .card-ssid { font-size: 10px; font-weight: bold; margin-top: 1mm; }
        .cred { margin-top: 2mm; border-top: 1px solid #e2e8f0; padding-top: 2mm; }
        .cred-row { margin-bottom: 1.5mm; }
        .cred-label { font-size: 7px; color: #64748b; text-transform: uppercase; }
        .cred-value { font-size: 12px; font-weight: bold; font-family: DejaVu Sans Mono, monospace; }
        .meta { font-size: 7px; color: #64748b; margin-top: 1mm; }
        .footer-note { font-size: 7px; color: #64748b; margin-top: 1mm; }
    </style>
</head>
<body>
    <p class="page-title">Voucher WiFi Mahasiswa — {{ $institution }}</p>
    <p class="page-sub">
        Total {{ $vouchers->count() }} voucher · dicetak {{ $printedAt }}
        @if ($loginUrl) · portal login: {{ $loginUrl }} @endif
    </p>

    <table class="grid">
        @foreach ($vouchers->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $voucher)
                    <td>
                        <div class="card">
                            <div class="card-head">Voucher Hotspot</div>
                            <div class="card-ssid">SSID: {{ $ssid ?: '—' }}</div>

                            <div class="cred">
                                <div class="cred-row">
                                    <div class="cred-label">Username (NIM)</div>
                                    <div class="cred-value">{{ $voucher->nim }}</div>
                                </div>
                                <div class="cred-row">
                                    <div class="cred-label">Password</div>
                                    <div class="cred-value">{{ $voucher->password }}</div>
                                </div>
                            </div>

                            <div class="meta">
                                {{ $voucher->student_name ?? '-' }}
                                @if ($voucher->program) · {{ $voucher->program }} @endif
                            </div>
                            <div class="meta">
                                @if ($voucher->profile) Paket: {{ $voucher->profile }} @endif
                                @if ($voucher->valid_until) · Berlaku s.d. {{ $voucher->valid_until->format('d/m/Y') }} @endif
                            </div>
                            <div class="footer-note">Jangan bagikan voucher ini ke orang lain.</div>
                        </div>
                    </td>
                @endforeach

                @if ($pair->count() === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
</body>
</html>
