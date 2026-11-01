import { Link } from '@inertiajs/react';
import VisuallyHidden from '@/components/VisuallyHidden';

export type TabItem = {
    href: string;
    label: string;
    current: boolean;
    /** A count beside the label; `label` is what a screen reader hears for it. */
    badge?: { text: string; label?: string; tone?: 'default' | 'alert' };
    /** The link's whole accessible name, when label and badge should read as one ("Data issues, 12 open"). */
    accessibleName?: string;
};

export type TabsProps = {
    /** Names the navigation, e.g. "Dashboard pages". */
    label: string;
    items: readonly TabItem[];
};

/**
 * The page tabs under the top bar. They change the URL, so they are links in a navigation with
 * aria-current="page" on the current one, not an ARIA tablist (which is for panels on one page).
 */
export default function Tabs({ label, items }: TabsProps) {
    return (
        <nav aria-label={label} className="-mx-1 overflow-x-auto">
            <ul className="flex gap-1">
                {items.map((item) => (
                    <li key={item.href}>
                        <Link
                            href={item.href}
                            aria-current={item.current ? 'page' : undefined}
                            aria-label={item.accessibleName}
                            className={`-mb-px inline-flex items-center border-b-2 px-2.5 pt-1.5 pb-2.5 text-sm whitespace-nowrap transition-colors duration-150 forced-colors:forced-color-adjust-none forced-colors:bg-[Canvas] forced-colors:text-[LinkText] ${
                                item.current
                                    ? 'border-deep font-semibold text-ink forced-colors:border-[Highlight]'
                                    : 'border-transparent text-ink-2 hover:border-rule-strong hover:text-ink'
                            }`}
                        >
                            {item.label}
                            {item.badge !== undefined && <Badge {...item.badge} current={item.current} />}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

function Badge({ text, label, tone = 'default', current }: NonNullable<TabItem['badge']> & { current: boolean }) {
    const colour = tone === 'alert' ? 'bg-bad text-panel' : current ? 'bg-deep text-panel' : 'bg-paper text-ink-2 ring-1 ring-rule ring-inset';

    return (
        <span className={`ml-2 min-w-5 rounded-full px-1.5 py-px text-center text-2xs font-semibold tabular-nums ${colour}`}>
            <span aria-hidden={label !== undefined}>{text}</span>
            {label !== undefined && <VisuallyHidden>{label}</VisuallyHidden>}
        </span>
    );
}
