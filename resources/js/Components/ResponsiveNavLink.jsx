import { Link } from '@inertiajs/react';

export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 ${
                active
                    ? 'border-indigo-400 bg-indigo-900/30 text-indigo-300 focus:border-indigo-500 focus:bg-indigo-900/40 focus:text-indigo-200'
                    : 'border-transparent text-slate-400 hover:border-slate-700 hover:bg-slate-900 hover:text-slate-200 focus:border-slate-700 focus:bg-slate-900 focus:text-slate-200'
            } text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
