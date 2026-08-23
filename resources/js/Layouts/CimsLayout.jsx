import { useEffect } from "react";
import { useShellSlot } from "@/Layouts/AppShell";

/**
 * Adapter halaman → shell.
 *
 * Sidebar & header sekarang hidup di `AppShell` yang di-mount sekali oleh
 * `app.jsx`, sehingga tidak ikut di-render ulang saat pindah menu. `CimsLayout`
 * hanya menempatkan judul halaman + isi konten ke dalam `<main>` dan menitipkan
 * props chrome (`unreadAlerts`, `onExport`) ke shell tersebut.
 *
 * API-nya dipertahankan sama seperti versi lama supaya semua halaman yang sudah
 * memakai `<CimsLayout header={...}>` tidak perlu diubah.
 */
export default function CimsLayout({ header, unreadAlerts = 0, onExport, children }) {
    const shell = useShellSlot();

    useEffect(() => {
        shell?.register({ unreadAlerts, onExport });
    }, [shell, unreadAlerts, onExport]);

    if (!shell && import.meta.env.DEV) {
        console.warn(
            "CimsLayout dirender di luar AppShell — sidebar & header tidak tersedia. " +
                "Pastikan halaman ini tidak masuk daftar GUEST_PAGES di resources/js/app.jsx.",
        );
    }

    return (
        <>
            {header && <div className="mb-6">{header}</div>}
            {children}
        </>
    );
}
