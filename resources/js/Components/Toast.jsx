import React, { createContext, useContext, useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

const ToastContext = createContext(null);

export const useToast = () => {
    const context = useContext(ToastContext);
    if (!context) {
        throw new Error('useToast must be used within a ToastProvider');
    }
    return context;
};

export const ToastProvider = ({ children }) => {
    const [toasts, setToasts] = useState([]);
    const { props } = usePage();

    const addToast = (type, message, duration = 4000) => {
        const id = Date.now() + Math.random().toString(36).substr(2, 9);
        setToasts((prev) => [...prev, { id, type, message, duration }]);
    };

    const removeToast = (id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    };

    // Watch Inertia flash messages
    useEffect(() => {
        if (props.flash?.success) {
            addToast('success', props.flash.success);
        }
        if (props.flash?.error) {
            addToast('error', props.flash.error);
        }
    }, [props.flash]);

    // Watch Inertia validation errors
    useEffect(() => {
        if (props.errors && Object.keys(props.errors).length > 0) {
            const errorCount = Object.keys(props.errors).length;
            addToast('error', `Terdapat ${errorCount} kesalahan input. Silakan periksa kembali form Anda.`);
        }
    }, [props.errors]);

    return (
        <ToastContext.Provider value={{ addToast, removeToast, toasts }}>
            {children}
            <ToastContainer toasts={toasts} removeToast={removeToast} />
        </ToastContext.Provider>
    );
};

const ToastContainer = ({ toasts, removeToast }) => {
    return (
        <div className="fixed top-5 right-5 z-[9999] flex flex-col space-y-3 w-full max-w-sm pointer-events-none">
            {toasts.map((toast) => (
                <ToastItem key={toast.id} toast={toast} onClose={() => removeToast(toast.id)} />
            ))}
        </div>
    );
};

const ToastItem = ({ toast, onClose }) => {
    const [progress, setProgress] = useState(100);
    const [isExiting, setIsExiting] = useState(false);

    useEffect(() => {
        const startTime = Date.now();
        const interval = setInterval(() => {
            const elapsed = Date.now() - startTime;
            const remaining = Math.max(0, 100 - (elapsed / toast.duration) * 100);
            setProgress(remaining);

            if (elapsed >= toast.duration) {
                clearInterval(interval);
                handleClose();
            }
        }, 30);

        return () => clearInterval(interval);
    }, [toast]);

    const handleClose = () => {
        setIsExiting(true);
        setTimeout(onClose, 300); // match fade-out animation duration
    };

    const styles = {
        success: {
            bg: 'bg-emerald-950/80 border-emerald-500/30 text-emerald-300 shadow-emerald-950/40',
            bar: 'bg-emerald-400',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            )
        },
        error: {
            bg: 'bg-rose-950/80 border-rose-500/30 text-rose-300 shadow-rose-950/40',
            bar: 'bg-rose-450',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            )
        },
        info: {
            bg: 'bg-sky-950/80 border-sky-500/30 text-sky-300 shadow-sky-950/40',
            bar: 'bg-sky-400',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            )
        },
        warning: {
            bg: 'bg-amber-950/80 border-amber-500/30 text-amber-300 shadow-amber-950/40',
            bar: 'bg-amber-400',
            icon: (
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            )
        }
    };

    const config = styles[toast.type] || styles.info;

    return (
        <div
            className={`pointer-events-auto relative overflow-hidden rounded-xl border p-4 shadow-xl backdrop-blur-md transition-all duration-300 flex items-start space-x-3 w-full ${
                config.bg
            } ${
                isExiting 
                    ? 'opacity-0 translate-x-10 scale-95' 
                    : 'opacity-100 translate-x-0 scale-100 animate-slide-in'
            }`}
        >
            <div className="shrink-0 mt-0.5">{config.icon}</div>
            <div className="flex-1 text-xs font-semibold leading-relaxed pr-2">{toast.message}</div>
            <button
                onClick={handleClose}
                className="shrink-0 text-white/40 hover:text-white transition-colors"
            >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div
                className="absolute bottom-0 left-0 h-1 transition-all duration-300 ease-out"
                style={{ width: `${progress}%`, backgroundColor: 'currentColor' }}
            />
        </div>
    );
};
