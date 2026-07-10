import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from '@/Components/Toast';
import { ConfirmationProvider } from '@/Components/ConfirmationModal';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        );
        
        const OriginalComponent = page.default;
        
        // Create the wrapped component which is the original component
        const WrappedComponent = OriginalComponent;
        
        // Wrap the layout function to ensure providers are at the root of the page tree
        const originalLayout = OriginalComponent.layout;
        
        WrappedComponent.layout = (pageElement) => {
            let layoutElement;
            if (typeof originalLayout === 'function') {
                layoutElement = originalLayout(pageElement);
            } else if (Array.isArray(originalLayout)) {
                layoutElement = originalLayout.concat(pageElement).reverse().reduce((prev, curr) => React.createElement(curr, null, prev));
            } else {
                layoutElement = pageElement;
            }
            
            return (
                <ToastProvider>
                    <ConfirmationProvider>
                        {layoutElement}
                    </ConfirmationProvider>
                </ToastProvider>
            );
        };
        
        return {
            ...page,
            default: WrappedComponent
        };
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
