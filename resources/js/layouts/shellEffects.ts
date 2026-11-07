import { useEffect, useRef } from 'react';

/**
 * A new page moves focus to its h1, so a screen reader hears where it landed; a filter change
 * reloads the same page and leaves focus on the select. Both layouts use it.
 */
export function useHeadingFocus(component: string) {
    const titleRef = useRef<HTMLHeadingElement>(null);
    const shownComponent = useRef(component);

    useEffect(() => {
        if (shownComponent.current !== component) {
            shownComponent.current = component;
            titleRef.current?.focus();
        }
    }, [component]);

    return titleRef;
}

/**
 * Keyboard focus and in-page links must never land under the sticky top bar (WCAG 2.4.11):
 * scroll-padding-top follows its real height, which changes with the filters and the width.
 */
export function useStickyHeaderOffset() {
    const headerRef = useRef<HTMLElement>(null);

    useEffect(() => {
        const header = headerRef.current;
        if (header === null || typeof ResizeObserver === 'undefined') {
            return;
        }
        const root = document.documentElement;
        const update = () => {
            const sticky = getComputedStyle(header).position === 'sticky';
            root.style.setProperty('--sticky-header-height', sticky ? `${header.offsetHeight + 12}px` : '0px');
        };
        const observer = new ResizeObserver(update);
        observer.observe(header);
        update();

        return () => {
            observer.disconnect();
            root.style.removeProperty('--sticky-header-height');
        };
    }, []);

    return headerRef;
}
