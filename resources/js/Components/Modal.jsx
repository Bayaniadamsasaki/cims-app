import {
    Dialog,
    DialogPanel,
    DialogTitle,
    Transition,
    TransitionChild,
} from '@headlessui/react';

/**
 * Primitive modal standar CIMS. Semua dialog aplikasi memakai berkas ini.
 *
 * Alasan primitive, bukan overlay per halaman: yang mahal dari modal bukan
 * tampilannya, tapi perilaku fokusnya — focus trap, Escape, pengembalian fokus ke
 * pemicu setelah tutup, dan `aria-modal` supaya screen reader berhenti membacakan
 * halaman di belakang scrim. `Dialog` Headless UI sudah memberi semuanya, jadi
 * menulis ulang handler Escape di tiap halaman hanya memperbanyak tempat yang bisa
 * salah. Jangan buat overlay `fixed inset-0` baru; pakai komponen ini.
 *
 * Geometri diambil dari pola yang sudah terbukti di halaman Hotspot: lembar bawah
 * di layar kecil (menempel ke tempat jempol berada, melebar penuh) dan dialog
 * tengah dari `sm` ke atas. Tinggi dipatok dengan `dvh`, bukan `vh`, supaya bilah
 * alamat browser ponsel yang muncul-hilang tidak ikut memotong baris tombol.
 *
 * `title` sekaligus menjadi `aria-labelledby` dialog. Bila `title` tidak diberikan,
 * `children` dirender apa adanya tanpa kepala — bentuk lama tetap berfungsi, tetapi
 * pemanggil wajib menyediakan labelnya sendiri.
 */

const MAX_WIDTH = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '3xl': 'sm:max-w-3xl',
    '4xl': 'sm:max-w-4xl',
    '5xl': 'sm:max-w-5xl',
    '6xl': 'sm:max-w-6xl',
};

/**
 * Tombol tutup standar: punya nama aksesibel dan focus ring yang terlihat.
 * Diekspor supaya modal yang menyusun kepalanya sendiri tidak perlu menulis
 * ulang pasangan `aria-label` + `focus-visible:ring` yang sebelumnya sering
 * tertinggal.
 */
export function ModalCloseButton({ onClose, label = 'Tutup', className = '' }) {
    return (
        <button
            type="button"
            onClick={onClose}
            aria-label={label}
            className={`shrink-0 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2 ${className}`}
        >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    );
}

export default function Modal({
    children,
    show = false,
    maxWidth = '2xl',
    closeable = true,
    onClose = () => {},
    title,
    description,
    footer,
}) {
    const close = () => {
        if (closeable) {
            onClose();
        }
    };

    const maxWidthClass = MAX_WIDTH[maxWidth] ?? MAX_WIDTH['2xl'];

    return (
        <Transition show={show}>
            <Dialog as="div" className="relative z-50" onClose={close}>
                <TransitionChild
                    enter="ease-out duration-200 motion-reduce:transition-none"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150 motion-reduce:transition-none"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" aria-hidden="true" />
                </TransitionChild>

                <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
                    <TransitionChild
                        enter="ease-out duration-200 motion-reduce:transition-none"
                        enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enterTo="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-150 motion-reduce:transition-none"
                        leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                        leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        className="w-full sm:mx-auto"
                    >
                        <DialogPanel
                            className={`relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-slate-200 bg-white shadow-2xl sm:max-h-[calc(100dvh-2rem)] sm:rounded-2xl ${maxWidthClass}`}
                        >
                            {title ? (
                                <div className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
                                    <div className="min-w-0">
                                        <DialogTitle className="text-lg font-bold text-slate-900">
                                            {title}
                                        </DialogTitle>
                                        {description ? (
                                            <p className="mt-0.5 text-xs text-slate-500">{description}</p>
                                        ) : null}
                                    </div>
                                    {closeable ? <ModalCloseButton onClose={close} /> : null}
                                </div>
                            ) : null}

                            {/* Yang bergulir adalah isi panelnya, bukan halaman di belakang scrim. */}
                            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">{children}</div>

                            {footer ? (
                                <div className="shrink-0 border-t border-slate-200 bg-white px-5 py-4 sm:px-6">
                                    {footer}
                                </div>
                            ) : null}
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
