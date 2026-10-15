import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            colors: { campus: { navy:'#142d53', blue:'#245fae', bg:'#f3f5f9', red:'#b42332', yellow:'#f3b94b', green:'#227a56', border:'#e2e8f0' } },
            boxShadow: { card:'0 2px 8px rgba(20,45,83,.05)' },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
