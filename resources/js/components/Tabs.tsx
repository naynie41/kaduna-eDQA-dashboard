import { Link } from '@inertiajs/react';
import VisuallyHidden from '@/components/VisuallyHidden';

export type TabItem = {
    href: string;
    label: string;
    current: boolean;
    /** A count beside the label; `label` is what a screen reader hears for it. */
    badge?: { text: string; label?: string; tone?: 'default' | 'alert' };
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
        <nav aria-label={label} className="overflow-x-auto">
            <ul className="flex gap-0.5">
                {items.map((item) => (
                    <li key={item.href}>
                        <Link
                            href={item.href}
                            aria-current={item.current ? 'page' : undefined}
                            className={`inline-flex items-center rounded-t-[5px] border-b-[2.5px] px-3 pt-2 pb-2.5 text-[13.5px] whitespace-nowrap forced-colors:forced-color-adjust-none forced-colors:bg-[Canvas] forced-colors:text-[LinkText] ${
                                item.current ? 'border-deep font-semibold text-deep forced-colors:border-[Highlight]' : 'border-transparent text-ink-2 hover:bg-paper hover:text-ink'
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
    const colour = tone === 'alert' ? 'bg-bad-bg text-bad' : current ? 'bg-wash text-deep' : 'bg-paper text-ink-2';

    return (
        <span className={`ml-1.5 rounded-full px-1.5 py-px text-[11px] ${colour}`}>
            <span aria-hidden={label !== undefined}>{text}</span>
            {label !== undefined && <VisuallyHidden>{label}</VisuallyHidden>}
        </span>
    );
}
