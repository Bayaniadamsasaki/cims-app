import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const [showingMobileMenu, setShowingMobileMenu] = useState(false);
    const [showingMasterDropdown, setShowingMasterDropdown] = useState(false);

    const hasRole = (role) => user?.roles?.includes(role);

    // Sidebar navigation items
    const navItems = [
        {
            name: 'Dashboard',
            route: 'dashboard',
            icon: (
                <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
            )
        },
        {
            name: 'Device Inventory',
            route: 'devices.index',
            icon: (
                <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
            )
        },
        {
            name: 'Live Monitoring',
            route: 'monitoring.index',
            icon: (
                <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2z" />
                </svg>
            )
        },
        {
            name: 'Maintenance Tickets',
            route: 'maintenance.index',
            icon: (
                <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
            )
        },
        {
            name: 'Reports & Export',
            route: 'reports.index',
            icon: (
                <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            )
        }
    ];

    const masterItems = [
        { name: 'Buildings', route: 'buildings.index' },
        { name: 'Vendors', route: 'vendors.index' },
        { name: 'Device Categories', route: 'device-categories.index' }
    ];

    return (
        <div className="min-h-screen bg-brand-bg text-white flex flex-col lg:flex-row">
            
            {/* Mobile Header Bar */}
            <div className="lg:hidden flex items-center justify-between bg-brand-bgSecondary border-b border-brand-border px-4 py-3 w-full z-30">
                <div className="flex items-center space-x-2">
                    <ApplicationLogo className="h-8 w-auto fill-current text-brand-primary" />
                    <span className="text-lg font-bold tracking-wider text-white">CIMS</span>
                </div>
                <button
                    onClick={() => setShowingMobileMenu(!showingMobileMenu)}
                    className="p-2 rounded-xl text-brand-textSecondary hover:text-white hover:bg-brand-cardElevated focus:outline-none"
                >
                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        {showingMobileMenu ? (
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        ) : (
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                        )}
                    </svg>
                </button>
            </div>

            {/* Sidebar Left (Desktop) */}
            <aside className={`fixed inset-y-0 left-0 bg-brand-bgSecondary border-r border-brand-border w-64 flex-col justify-between z-20 transition-transform duration-300 lg:flex lg:translate-x-0 ${
                showingMobileMenu ? 'translate-x-0 flex' : '-translate-x-full hidden'
            }`}>
                <div>
                    {/* Brand Logo */}
                    <div className="h-16 flex items-center px-6 border-b border-brand-border bg-brand-bg">
                        <Link href="/dashboard" className="flex items-center space-x-3">
                            <ApplicationLogo className="h-9 w-auto fill-current text-brand-primary" />
                            <div>
                                <span className="text-xl font-black tracking-wider text-white">CIMS</span>
                                <span className="block text-[10px] text-brand-primary font-semibold tracking-widest mt-[-2px]">CAMPUS NET</span>
                            </div>
                        </Link>
                    </div>

                    {/* Navigation Links */}
                    <nav className="px-4 py-6 space-y-1">
                        {navItems.map((item, idx) => {
                            const active = route().current(item.route) || (item.route === 'devices.index' && route().current('devices.*'));
                            return (
                                <Link
                                    key={idx}
                                    href={route(item.route)}
                                    className={`flex items-center px-4 py-3 text-sm font-semibold rounded-xl transition duration-150 ${
                                        active
                                            ? 'bg-brand-primary text-slate-950 shadow-md shadow-brand-primary/10'
                                            : 'text-brand-textSecondary hover:text-brand-primary hover:bg-brand-primary/10'
                                    }`}
                                >
                                    {item.icon}
                                    {item.name}
                                </Link>
                            );
                        })}

                        {/* Master Data Dropdown */}
                        <div className="pt-2">
                            <button
                                onClick={() => setShowingMasterDropdown(!showingMasterDropdown)}
                                className={`flex items-center justify-between w-full px-4 py-3 text-sm font-semibold rounded-xl text-brand-textSecondary hover:text-brand-primary hover:bg-brand-primary/10 transition duration-150`}
                            >
                                <div className="flex items-center">
                                    <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    <span>Master Data</span>
                                </div>
                                <svg className={`h-4 w-4 transform transition-transform duration-150 ${showingMasterDropdown ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            
                            <div className={`mt-1 pl-8 space-y-1 overflow-hidden transition-all duration-250 ${
                                showingMasterDropdown ? 'max-h-40 opacity-100' : 'max-h-0 opacity-0'
                            }`}>
                                {masterItems.map((item, idx) => {
                                    const active = route().current(item.route);
                                    return (
                                        <Link
                                            key={idx}
                                            href={route(item.route)}
                                            className={`block px-4 py-2 text-xs font-semibold rounded-lg transition duration-150 ${
                                                active
                                                    ? 'text-brand-primary bg-brand-card'
                                                    : 'text-brand-textMuted hover:text-brand-textSecondary hover:bg-brand-primary/5'
                                            }`}
                                        >
                                            {item.name}
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>

                        {/* User Management (Super Admin only) */}
                        {hasRole('Super Admin') && (
                            <div className="pt-2">
                                <Link
                                    href={route('users.index')}
                                    className={`flex items-center px-4 py-3 text-sm font-semibold rounded-xl transition duration-150 ${
                                        route().current('users.index') || route().current('users.*')
                                            ? 'bg-brand-primary text-slate-950 shadow-md shadow-brand-primary/10'
                                            : 'text-brand-textSecondary hover:text-brand-primary hover:bg-brand-primary/10'
                                    }`}
                                >
                                    <svg className="h-5 w-5 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    User Management
                                </Link>
                            </div>
                        )}
                    </nav>
                </div>

                {/* Bottom User Profile Section */}
                <div className="p-4 border-t border-brand-border bg-brand-bg">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-3 overflow-hidden">
                            <div className="h-10 w-10 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary font-black shrink-0">
                                {user?.name?.charAt(0).toUpperCase()}
                            </div>
                            <div className="overflow-hidden">
                                <div className="text-sm font-bold text-white truncate">{user?.name}</div>
                                <span className="inline-block text-[9px] bg-brand-primary/15 text-brand-primary border border-brand-primary/25 px-2 py-0.5 rounded-full font-black mt-0.5">
                                    {user?.roles?.[0] || 'Member'}
                                </span>
                            </div>
                        </div>

                        <div className="relative">
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button className="p-2 rounded-lg text-brand-textSecondary hover:text-white hover:bg-brand-card transition">
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>

                                <Dropdown.Content>
                                    <Dropdown.Link href={route('profile.edit')}>
                                        Profile Settings
                                    </Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        Log Out
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </aside>

            {/* Main Content Area */}
            <div className="flex-1 lg:pl-64 flex flex-col min-h-screen">
                {header && (
                    <header className="bg-brand-bgSecondary/60 border-b border-brand-border shadow-lg backdrop-blur-md sticky top-0 z-10 py-6">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            {header}
                        </div>
                    </header>
                )}

                <main className="flex-1">
                    {children}
                </main>
            </div>
        </div>
    );
}
