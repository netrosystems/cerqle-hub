import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Poppins', ...defaultTheme.fontFamily.sans],
                display: ['Poppins', ...defaultTheme.fontFamily.sans],
            },
            // Cerqle palette - primary plum #3E2A49, secondary lilac #8F5FA7.
            // Source of truth: ./.branding
            colors: {
                surface: {
                    DEFAULT: '#fbf9fd',
                    subtle: '#f5eff8',
                },
                secondary: {
                    50: '#f7f5f8',
                    100: '#ebe6ee',
                    200: '#d8cede',
                    300: '#b9a8c2',
                    400: '#9980a7',
                    500: '#80638e',
                    600: '#684e74',
                    700: '#543f5e',
                    800: '#46344f',
                    900: '#3e2a49',
                    950: '#281a31',
                },
                brand: {
                    50: '#faf7fc',
                    100: '#f1e8f5',
                    200: '#e3d5ea',
                    300: '#c7a3d7',
                    400: '#ad7ac2',
                    500: '#8f5fa7',
                    600: '#77488e',
                    700: '#603a72',
                    800: '#50305e',
                    900: '#3e2a49',
                    950: '#281a31',
                },
                // Cool accent from the guideline cover mark.
                accent: {
                    50: '#f8fbfd',
                    100: '#edf6fb',
                    200: '#d4edf7',
                    300: '#aee0ef',
                    400: '#78cde4',
                    500: '#45b6d6',
                    600: '#2d99bc',
                    700: '#257b98',
                    800: '#24677e',
                    900: '#23576b',
                    950: '#173747',
                },
                // Danger / destructive (coral-red)
                coral: {
                    50: '#fff3f1',
                    100: '#ffe4df',
                    200: '#ffcabf',
                    300: '#ffa593',
                    400: '#fb7355',
                    500: '#f04e2e',
                    600: '#d8331a',
                    700: '#b32512',
                    800: '#931f13',
                    900: '#7a1e16',
                    950: '#420a07',
                },
                neutral: {
                    50: '#fafafa',
                    100: '#f4f4f5',
                    200: '#e4e4e7',
                    300: '#d4d4d8',
                    400: '#a1a1aa',
                    500: '#71717a',
                    600: '#52525b',
                    700: '#3f3f46',
                    800: '#27272a',
                    900: '#18181b',
                    950: '#0a0a0b',
                },
            },
            // Soft borders
            borderWidth: {
                soft: '1px',
            },
            borderColor: {
                DEFAULT: 'rgb(228 228 231 / 0.8)',
                soft: 'rgb(228 228 231 / 0.6)',
                muted: 'rgb(228 228 231 / 0.4)',
            },
            borderRadius: {
                soft: '0.5rem',
                'soft-lg': '0.75rem',
                'soft-xl': '1rem',
            },
            // Subtle shadows
            boxShadow: {
                soft: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 2px -1px rgb(0 0 0 / 0.04)',
                'soft-md': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05)',
                'soft-lg': '0 10px 15px -3px rgb(0 0 0 / 0.06), 0 4px 6px -4px rgb(0 0 0 / 0.06)',
                'soft-xl': '0 20px 25px -5px rgb(0 0 0 / 0.06), 0 8px 10px -6px rgb(0 0 0 / 0.06)',
                inner: 'inset 0 1px 2px 0 rgb(0 0 0 / 0.04)',
            },
            // Spacing scale (align with design)
            spacing: {
                '4.5': '1.125rem',
                '13': '3.25rem',
                '15': '3.75rem',
                '18': '4.5rem',
                '22': '5.5rem',
                '30': '7.5rem',
            },
            transitionDuration: {
                150: '150ms',
                250: '250ms',
            },
            transitionTimingFunction: {
                smooth: 'cubic-bezier(0.4, 0, 0.2, 1)',
            },
            // ── Landing-page animation system (Cerqle) ──
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(28px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                'scale-in': {
                    '0%': { opacity: '0', transform: 'scale(0.94)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-18px)' },
                },
                'float-slow': {
                    '0%, 100%': { transform: 'translate(0, 0)' },
                    '50%': { transform: 'translate(0, -26px)' },
                },
                marquee: {
                    '0%': { transform: 'translateX(0)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
                'gradient-pan': {
                    '0%, 100%': { backgroundPosition: '0% 50%' },
                    '50%': { backgroundPosition: '100% 50%' },
                },
                'pulse-ring': {
                    '0%': { transform: 'scale(0.8)', opacity: '0.5' },
                    '100%': { transform: 'scale(2.2)', opacity: '0' },
                },
                'spin-slow': {
                    '0%': { transform: 'rotate(0deg)' },
                    '100%': { transform: 'rotate(360deg)' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.7s cubic-bezier(0.16, 1, 0.3, 1) both',
                'fade-in': 'fade-in 0.8s ease-out both',
                'scale-in': 'scale-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) both',
                float: 'float 6s ease-in-out infinite',
                'float-slow': 'float-slow 9s ease-in-out infinite',
                marquee: 'marquee 32s linear infinite',
                shimmer: 'shimmer 2.5s linear infinite',
                'gradient-pan': 'gradient-pan 8s ease infinite',
                'pulse-ring': 'pulse-ring 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite',
                'spin-slow': 'spin-slow 24s linear infinite',
            },
        },
    },

    plugins: [forms],
};
