import React, { createContext, useContext, useState } from 'react';
import { DialogTitle } from '@headlessui/react';
import Modal from './Modal';

const ConfirmationContext = createContext(null);

export const useConfirmation = () => {
    const context = useContext(ConfirmationContext);
    if (!context) {
        throw new Error('useConfirmation must be used within a ConfirmationProvider');
    }
    return context;
};

export const ConfirmationProvider = ({ children }) => {
    const [config, setConfig] = useState(null);

    const confirmAction = (options) => {
        return new Promise((resolve) => {
            setConfig({
                ...options,
                onConfirm: () => {
                    options.onConfirm?.();
                    setConfig(null);
                    resolve(true);
                },
                onCancel: () => {
                    options.onCancel?.();
                    setConfig(null);
                    resolve(false);
                }
            });
        });
    };

    return (
        <ConfirmationContext.Provider value={{ confirmAction }}>
            {children}
            {config && <ConfirmationDialog config={config} />}
        </ConfirmationContext.Provider>
    );
};

export const ConfirmationDialog = ({ config }) => {
    const {
        title = 'Confirmation',
        message = 'Are you sure you want to perform this action?',
        confirmLabel = 'Confirm',
        cancelLabel = 'Cancel',
        onConfirm,
        onCancel,
        variant = 'danger' // 'danger' | 'warning' | 'info'
    } = config;

    const styles = {
        danger: {
            iconBg: 'bg-red-50 text-red-600 border-red-100',
            button: 'bg-red-600 hover:bg-red-700 text-white focus:ring-red-600',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
            )
        },
        warning: {
            iconBg: 'bg-amber-50 text-amber-600 border-amber-100',
            button: 'bg-amber-600 hover:bg-amber-700 text-white focus:ring-amber-600',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            )
        },
        info: {
            iconBg: 'bg-blue-50 text-blue-600 border-blue-100',
            button: 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-600',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            )
        }
    };

    const currentStyle = styles[variant] || styles.danger;

    /**
     * Dialog konfirmasi ikut memakai primitive `Modal` supaya focus trap, Escape,
     * `aria-modal`, dan pengembalian fokus datang dari satu tempat. Escape dan klik
     * di luar panel dipetakan ke `onCancel` — jalan keluar dari sebuah konfirmasi
     * harus selalu berarti "tidak", bukan "ya".
     *
     * Kepalanya disusun sendiri (bukan lewat prop `title`) karena ikonnya berada di
     * atas judul dan seluruh isinya rata tengah. `DialogTitle` tetap dipakai agar
     * dialog punya `aria-labelledby` yang benar.
     */
    return (
        <Modal show onClose={onCancel} maxWidth="sm">
            <div className="flex flex-col items-center p-6 text-center">
                <div
                    className={`p-3.5 rounded-full border ${currentStyle.iconBg} mb-4 flex items-center justify-center`}
                    aria-hidden="true"
                >
                    {currentStyle.icon}
                </div>

                <DialogTitle className="mb-2 text-lg font-bold text-slate-900">{title}</DialogTitle>

                <p className="mb-6 max-w-xs text-xs leading-relaxed text-slate-500">{message}</p>

                <div className="flex w-full gap-3">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="flex-1 rounded-xl border border-slate-200 bg-white py-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        className={`flex-1 rounded-xl py-2.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ${currentStyle.button}`}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </Modal>
    );
};
