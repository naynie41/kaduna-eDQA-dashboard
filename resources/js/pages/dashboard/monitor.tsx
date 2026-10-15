import PagePlaceholder from '@/components/PagePlaceholder';

/** Dashboard monitor (ARCHITECTURE.md §9.2). The title, filters and tabs come from AppShell. */
export default function Monitor() {
    return <PagePlaceholder deferred={['owners', 'coverage', 'windows']} />;
}
