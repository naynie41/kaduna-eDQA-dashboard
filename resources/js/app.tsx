import { createInertiaApp } from '@inertiajs/react';
import AppShell from '@/layouts/AppShell';
import AuthLayout from '@/layouts/AuthLayout';

const appName = import.meta.env.VITE_APP_NAME;

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Sign-in and account-security pages use the centred panel; everything else the shell.
    layout: (name) => (name.startsWith('auth/') ? AuthLayout : AppShell),
    strictMode: true,
    progress: {
        color: '#12645B',
    },
});
