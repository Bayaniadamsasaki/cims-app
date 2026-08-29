import { useCallback, useEffect, useState } from 'react';
import { IconEye, IconEyeOff } from '@/Components/Cims/icons';

/**
 * Tombol "mata" buka/tutup password perangkat.
 *
 * Dipakai HANYA di modal detail perangkat, sengaja tidak di baris tabel
 * inventaris: membuka password dari tabel berarti nilainya bisa muncul sementara
 * daftar seratus perangkat lain ikut terpampang di layar, sedangkan membuka
 * modal detail adalah tindakan sadar untuk satu perangkat tertentu.
 *
 * Nilainya TIDAK ikut dikirim di props halaman: ia baru diminta ke endpoint
 * `devices.credential` saat tombolnya ditekan, satu perangkat per permintaan.
 * Jadi membuka halaman inventaris tidak menaruh satu pun password router di
 * HTML, dan setiap pembukaan meninggalkan jejak di activity log.
 *
 * Yang sudah terbuka tetap tampil sampai ditutup sendiri lewat tombol yang sama,
 * atau sampai modalnya berpindah ke perangkat lain — tidak ada timer yang
 * menutupnya diam-diam.
 */
export default function CredentialReveal({ deviceId, hasCredentials = false, canView = false }) {
    const [password, setPassword] = useState(null);
    const [visible, setVisible] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    /** Tutup dan buang nilainya dari state — jangan disimpan lebih lama dari perlu. */
    const hide = useCallback(() => {
        setVisible(false);
        setPassword(null);
    }, []);

    // Modal detail dipakai ulang untuk perangkat lain: ganti perangkat = tutup,
    // supaya password perangkat sebelumnya tidak ikut terbawa.
    useEffect(() => {
        hide();
        setError(null);
    }, [deviceId, hide]);

    const reveal = async () => {
        setError(null);
        setLoading(true);

        try {
            const { data } = await window.axios.get(route('devices.credential', deviceId));

            if (!data.has_credentials || !data.password) {
                setError('Perangkat ini belum punya password tersimpan.');
                return;
            }

            setPassword(data.password);
            setVisible(true);
        } catch (e) {
            const status = e?.response?.status;

            setError(
                status === 403
                    ? 'Anda tidak punya izin membuka kredensial perangkat.'
                    : status === 429
                        ? 'Terlalu banyak permintaan kredensial. Tunggu sebentar lalu coba lagi.'
                        : status === 404
                            ? 'Perangkat sudah tidak ada di inventaris.'
                            : 'Gagal mengambil kredensial dari server.',
            );
        } finally {
            setLoading(false);
        }
    };

    // Tidak ada yang bisa dibuka.
    if (!hasCredentials) {
        return <span className="text-slate-400">Belum diisi</span>;
    }

    // Tanpa izin, tampilannya persis seperti sebelum fitur ini ada.
    if (!canView) {
        return (
            <span title="Password tersimpan terenkripsi. Perlu izin 'view device credentials' untuk membukanya.">
                Tersimpan (tidak ditampilkan)
            </span>
        );
    }

    return (
        <span className="flex flex-wrap items-center gap-2">
            <span
                className={`font-mono ${visible ? 'font-semibold text-slate-900' : 'text-slate-500'}`}
                aria-live="polite"
            >
                {loading ? 'membuka...' : visible ? password : '••••••••'}
            </span>

            <button
                type="button"
                onClick={visible ? hide : reveal}
                disabled={loading}
                aria-pressed={visible}
                aria-label={visible ? 'Sembunyikan password perangkat' : 'Tampilkan password perangkat'}
                title={visible ? 'Sembunyikan password' : 'Tampilkan password'}
                className="inline-flex items-center rounded p-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500 disabled:opacity-50"
            >
                {visible ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
            </button>

            {error && (
                <span className="text-[10px] font-semibold text-rose-600" role="alert">
                    {error}
                </span>
            )}
        </span>
    );
}
