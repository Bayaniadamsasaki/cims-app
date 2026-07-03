import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    bg: '#0B0F0D',
                    bgSecondary: '#111513',
                    card: '#161A18',
                    cardElevated: '#1C201E',
                    primary: '#22C55E',
                    primaryHover: '#16A34A',
                    primaryLight: '#86EFAC',
                    border: 'rgba(255,255,255,0.06)',
                    textPrimary: '#FFFFFF',
                    textSecondary: '#A1A1AA',
                    textMuted: '#71717A',
                }
            }
        },
    },

    plugins: [forms],
};
