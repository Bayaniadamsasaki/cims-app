import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [showPassword, setShowPassword] = useState(false);

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Sign In — CIMS" />

            <div className="flex min-h-screen bg-brand-bg">
                {/* Left Side — Branding Panel */}
                <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden">
                    {/* Animated gradient background */}
                    <div className="absolute inset-0 bg-gradient-to-br from-brand-primary/20 via-brand-bg to-emerald-900/30" />
                    
                    {/* Grid pattern overlay */}
                    <div 
                        className="absolute inset-0 opacity-[0.04]"
                        style={{
                            backgroundImage: `
                                linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px),
                                linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px)
                            `,
                            backgroundSize: '60px 60px',
                        }}
                    />

                    {/* Floating orbs */}
                    <div className="absolute top-1/4 left-1/4 w-72 h-72 bg-brand-primary/10 rounded-full blur-[100px] animate-pulse" />
                    <div className="absolute bottom-1/3 right-1/4 w-96 h-96 bg-emerald-600/8 rounded-full blur-[120px] animate-pulse" style={{ animationDelay: '1s' }} />
                    
                    {/* Content */}
                    <div className="relative z-10 flex flex-col justify-between p-12 w-full">
                        {/* Logo & Title */}
                        <div>
                            <div className="flex items-center space-x-3">
                                <div className="h-10 w-10 rounded-xl bg-brand-primary flex items-center justify-center shadow-lg shadow-brand-primary/25">
                                    <svg className="h-6 w-6 text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" />
                                    </svg>
                                </div>
                                <span className="text-xl font-bold text-white tracking-tight">CIMS</span>
                            </div>
                        </div>

                        {/* Hero Text */}
                        <div className="max-w-md">
                            <h1 className="text-4xl font-bold text-white leading-tight tracking-tight">
                                Campus Infrastructure
                                <br />
                                <span className="text-brand-primary">Monitoring System</span>
                            </h1>
                            <p className="mt-4 text-brand-textSecondary text-base leading-relaxed">
                                Real-time monitoring, device inventory, and maintenance management for campus network infrastructure.
                            </p>

                            {/* Feature highlights */}
                            <div className="mt-8 space-y-3">
                                {[
                                    { icon: '📡', text: 'Real-time device monitoring & alerting' },
                                    { icon: '🖥️', text: 'Complete network inventory management' },
                                    { icon: '🔧', text: 'Maintenance ticket tracking' },
                                ].map((item, i) => (
                                    <div key={i} className="flex items-center space-x-3 text-sm text-brand-textSecondary">
                                        <span className="text-lg">{item.icon}</span>
                                        <span>{item.text}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Footer */}
                        <p className="text-xs text-brand-textMuted">
                            © 2026 CIMS — Built for campus network operations
                        </p>
                    </div>
                </div>

                {/* Right Side — Login Form */}
                <div className="flex flex-1 flex-col justify-center px-6 py-12 lg:px-16 xl:px-24">
                    <div className="mx-auto w-full max-w-sm">
                        {/* Mobile logo */}
                        <div className="lg:hidden flex items-center space-x-3 mb-10">
                            <div className="h-10 w-10 rounded-xl bg-brand-primary flex items-center justify-center shadow-lg shadow-brand-primary/25">
                                <svg className="h-6 w-6 text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" />
                                </svg>
                            </div>
                            <span className="text-xl font-bold text-white tracking-tight">CIMS</span>
                        </div>

                        {/* Title */}
                        <div className="mb-8">
                            <h2 className="text-2xl font-bold text-white tracking-tight">
                                Welcome back
                            </h2>
                            <p className="mt-2 text-sm text-brand-textSecondary">
                                Sign in to your account to continue
                            </p>
                        </div>

                        {status && (
                            <div className="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm font-medium text-emerald-400">
                                {status}
                            </div>
                        )}

                        <form onSubmit={submit} className="space-y-5">
                            {/* Email */}
                            <div>
                                <label htmlFor="email" className="block text-xs font-semibold text-brand-textSecondary mb-1.5">
                                    Email Address
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <svg className="h-4 w-4 text-brand-textMuted" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                        </svg>
                                    </div>
                                    <input
                                        id="email"
                                        type="email"
                                        name="email"
                                        value={data.email}
                                        autoComplete="username"
                                        autoFocus
                                        placeholder="Masukkan email"
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="w-full pl-10 rounded-xl bg-brand-bg border-brand-border text-sm text-white placeholder-brand-textMuted focus:border-brand-primary focus:ring-brand-primary transition"
                                    />
                                </div>
                                <InputError message={errors.email} className="mt-1.5 text-xs text-rose-400" />
                            </div>

                            {/* Password */}
                            <div>
                                <label htmlFor="password" className="block text-xs font-semibold text-brand-textSecondary mb-1.5">
                                    Password
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <svg className="h-4 w-4 text-brand-textMuted" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                    </div>
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        autoComplete="current-password"
                                        placeholder="Masukkan password"
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="w-full pl-10 pr-10 rounded-xl bg-brand-bg border-brand-border text-sm text-white placeholder-brand-textMuted focus:border-brand-primary focus:ring-brand-primary transition"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-brand-textMuted hover:text-brand-textSecondary transition"
                                    >
                                        {showPassword ? (
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            </svg>
                                        ) : (
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                                <InputError message={errors.password} className="mt-1.5 text-xs text-rose-400" />
                            </div>

                            {/* Remember & Forgot */}
                            <div className="flex items-center justify-between">
                                <label className="flex items-center cursor-pointer group">
                                    <input
                                        type="checkbox"
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) => setData('remember', e.target.checked)}
                                        className="rounded border-brand-border bg-brand-bg text-brand-primary shadow-sm focus:ring-brand-primary focus:ring-offset-0 focus:ring-offset-brand-bg"
                                    />
                                    <span className="ms-2 text-sm text-brand-textSecondary group-hover:text-white transition">
                                        Remember me
                                    </span>
                                </label>

                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-sm text-brand-primary hover:text-brand-primaryLight transition font-medium"
                                    >
                                        Forgot password?
                                    </Link>
                                )}
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={processing}
                                className={`w-full rounded-xl bg-brand-primary px-4 py-3 text-sm font-bold text-slate-950 shadow-lg shadow-brand-primary/20 hover:bg-brand-primaryHover hover:shadow-brand-primary/30 transition-all duration-200 ${
                                    processing ? 'opacity-60 cursor-not-allowed' : ''
                                }`}
                            >
                                {processing ? (
                                    <span className="flex items-center justify-center space-x-2">
                                        <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                        </svg>
                                        <span>Signing in...</span>
                                    </span>
                                ) : (
                                    'Sign In'
                                )}
                            </button>
                        </form>


                    </div>
                </div>
            </div>
        </>
    );
}
