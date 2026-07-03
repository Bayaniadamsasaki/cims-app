import { Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={
                'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ' +
                (active
                    ? 'border-indigo-400 text-white focus:border-indigo-500'
                    : 'border-transparent text-slate-400 hover:border-slate-350 hover:text-slate-200 focus:border-slate-500 focus:text-slate-200') +
                className
            }
        >
            {children}
        </Link>
    );
}
