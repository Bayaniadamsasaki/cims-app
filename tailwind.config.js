import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Ubuntu', 'sans-serif'],
            },
            colors: {
                /**
                 * Palet `brand` dipakai seluruh halaman lama. Nilainya diganti ke
                 * tema terang CIMS (Docs/design_cims_dashboard.md §3) supaya satu
                 * definisi warna berlaku untuk semua modul, bukan cuma dashboard.
                 * Nama token dipertahankan agar kelas yang sudah ada tetap valid.
                 */
                brand: {
                    bg: '#F8FAFC',           // slate-50  — latar halaman
                    bgSecondary: '#FFFFFF',  // white     — permukaan input & panel
                    card: '#FFFFFF',         // white     — kartu
                    cardElevated: '#F8FAFC', // slate-50  — kartu bertumpuk/hover
                    primary: '#2563EB',      // blue-600  — aksi utama
                    primaryHover: '#1D4ED8', // blue-700
                    primarySoft: '#EFF6FF',  // blue-50   — tint lembut (aksen)
                    primaryLight: '#DBEAFE', // blue-100  — dipertahankan utk kompatibilitas
                    border: '#E2E8F0',       // slate-200 — garis pemisah
                    textPrimary: '#0F172A',  // slate-900
                    textSecondary: '#475569',// slate-600 — teks isi & label
                    textMuted: '#64748B',    // slate-500 — keterangan kecil
                }
            }

        },
    },

    plugins: [forms],
};
