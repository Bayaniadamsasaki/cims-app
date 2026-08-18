import React, { createContext, useContext, useState } from 'react';

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

    return (
        <div className="fixed inset-0 z-[10000] flex items-center justify-center overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-sm">
            <div className="relative flex w-full max-w-sm flex-col items-center rounded-2xl border border-slate-100 bg-white p-6 text-center shadow-xl animate-zoom-in">

                {/* Variant Styled Icon */}
                <div className={`p-3.5 rounded-full border ${currentStyle.iconBg} mb-4 flex items-center justify-center`}>
                    {currentStyle.icon}
                </div>

                {/* Title */}
                <h3 className="mb-2 text-lg font-bold text-slate-900">{title}</h3>

                {/* Message */}
                <p className="mb-6 max-w-xs text-xs leading-relaxed text-slate-500">{message}</p>

                {/* Actions */}
                <div className="flex w-full space-x-3">
                    <button
                        onClick={onCancel}
                        className="flex-1 rounded-xl border border-slate-200 bg-white py-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 active:scale-95"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={`flex-1 rounded-xl py-2.5 text-xs font-semibold transition active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2 ${currentStyle.button}`}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
};
