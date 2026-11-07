import { createInertiaApp } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import AuthLayout from '@/layouts/AuthLayout';
import PublicLayout from '@/layouts/PublicLayout';

const appName = import.meta.env.VITE_APP_NAME;

void createInertiaApp({
    // "Home · Kaduna eDQA" (ACCESSIBILITY: every page has a unique, descriptive title).
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Sign-in screens use the centred panel; the dashboard the public layout (ARCHITECTURE.md §9.0);
    // everything else is the admin area.
    layout: (name) => (name.startsWith('auth/') ? AuthLayout : name.startsWith('dashboard/') ? PublicLayout : AdminLayout),
    strictMode: true,
    progress: {
        color: '#12645B',
    },
});
