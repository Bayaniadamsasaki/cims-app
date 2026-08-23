import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from '@/Components/Toast';
import { ConfirmationProvider } from '@/Components/ConfirmationModal';
import AppShell from '@/Layouts/AppShell';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Halaman tanpa chrome dashboard (login, registrasi, landing). Selain daftar ini
 * semua halaman dibungkus `AppShell`, jadi sidebar + header hanya di-mount sekali
 * dan bertahan di seluruh navigasi Inertia.
 */
const GUEST_PAGES = /^(Auth\/|Welcome$)/;

/**
 * Penanda agar pembungkusan layout hanya terjadi satu kali per modul halaman.
 * `resolve()` dipanggil ulang setiap kunjungan sementara modulnya di-cache oleh
 * Vite; tanpa penanda ini layout lama akan terus dibungkus lagi sehingga pohon
 * React makin dalam tiap navigasi (shell ikut remount dan halaman terasa berat).
 */
const WRAPPED = Symbol.for('cims.layoutWrapped');

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        );

        const Component = page.default;

        if (!Component[WRAPPED]) {
            const originalLayout = Component.layout;

            Component.layout = (pageElement) => {
                let layoutElement;
                if (typeof originalLayout === 'function') {
                    layoutElement = originalLayout(pageElement);
                } else if (Array.isArray(originalLayout)) {
                    layoutElement = originalLayout
                        .concat(pageElement)
                        .reverse()
                        .reduce((prev, curr) => React.createElement(curr, null, prev));
                } else {
                    layoutElement = pageElement;
                }

                return (
                    <ToastProvider>
                        <ConfirmationProvider>
                            {GUEST_PAGES.test(name) ? layoutElement : <AppShell>{layoutElement}</AppShell>}
                        </ConfirmationProvider>
                    </ToastProvider>
                );
            };

            Component[WRAPPED] = true;
        }

        return page;
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
