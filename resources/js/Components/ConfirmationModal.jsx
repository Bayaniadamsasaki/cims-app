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
            iconBg: 'bg-rose-500/10 text-rose-450 border-rose-500/30',
            button: 'bg-rose-600 hover:bg-rose-500 text-white focus:ring-rose-500 hover:shadow-[0_0_15px_rgba(244,63,94,0.35)]',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
            )
        },
        warning: {
            iconBg: 'bg-amber-500/10 text-amber-450 border-amber-500/30',
            button: 'bg-amber-600 hover:bg-amber-500 text-white focus:ring-amber-500 hover:shadow-[0_0_15px_rgba(245,158,11,0.35)]',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            )
        },
        info: {
            iconBg: 'bg-brand-primary/10 text-brand-primary border-brand-primary/30',
            button: 'bg-brand-primary hover:bg-brand-primaryLight text-slate-950 font-bold focus:ring-brand-primary hover:shadow-[0_0_15px_rgba(0,240,255,0.35)]',
            icon: (
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            )
        }
    };

    const currentStyle = styles[variant] || styles.danger;

    return (
        <div className="fixed inset-0 z-[10000] overflow-y-auto bg-slate-950/80 flex items-center justify-center p-4 backdrop-blur-md">
            <div className="relative w-full max-w-sm rounded-2xl bg-brand-card border border-brand-border p-6 shadow-2xl animate-zoom-in text-center flex flex-col items-center">
                
                {/* Variant Styled Icon */}
                <div className={`p-3.5 rounded-full border ${currentStyle.iconBg} mb-4 flex items-center justify-center`}>
                    {currentStyle.icon}
                </div>

                {/* Title */}
                <h3 className="text-lg font-bold text-white mb-2">{title}</h3>

                {/* Message */}
                <p className="text-xs text-brand-textSecondary leading-relaxed mb-6 max-w-xs">{message}</p>

                {/* Actions */}
                <div className="flex w-full space-x-3">
                    <button
                        onClick={onCancel}
                        className="flex-1 rounded-xl bg-brand-bg hover:bg-brand-bgSecondary border border-brand-border text-xs text-white font-semibold py-2.5 transition active:scale-95"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        onClick={onConfirm}
                        className={`flex-1 rounded-xl text-xs font-semibold py-2.5 transition active:scale-95 ${currentStyle.button}`}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
};
