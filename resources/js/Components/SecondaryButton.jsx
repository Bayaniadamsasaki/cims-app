export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            type={type}
            className={
                `inline-flex items-center rounded-xl border border-brand-border bg-transparent px-4 py-2.5 text-xs font-semibold uppercase tracking-widest text-brand-textSecondary shadow-sm transition duration-150 ease-in-out hover:bg-brand-bgSecondary focus:outline-none focus:ring-2 focus:ring-brand-border focus:ring-offset-2 focus:ring-offset-brand-bg disabled:opacity-25 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
