import { Link } from '@inertiajs/react';

/**
 * Shell halaman autentikasi non-login (register, lupa password, reset, verifikasi).
 * Mengikuti bahasa visual Login: latar brand-bg, kartu putih rounded-xl, brand di atas.
 */
export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-brand-bg px-4 py-12">
            <div className="mb-8 flex flex-col items-center text-center">
                <Link href="/" className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-primary">
                    <svg
                        className="h-5 w-5 text-white"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={2.5}
                        aria-hidden="true"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </Link>
                <p className="mt-3 text-lg font-semibold tracking-tight text-slate-900">CIMS</p>
            </div>

            <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                {children}
            </div>
        </div>
    );
}