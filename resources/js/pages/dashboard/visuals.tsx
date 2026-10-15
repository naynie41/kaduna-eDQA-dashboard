import PagePlaceholder from '@/components/PagePlaceholder';

/** Dashboard visuals (ARCHITECTURE.md §9.2). The title, filters and tabs come from AppShell. */
export default function Visuals() {
    return <PagePlaceholder deferred={['monthSlots', 'lgas']} />;
}
