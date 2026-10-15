import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';

type Announce = (message: string) => void;

const AnnounceContext = createContext<Announce>(() => undefined);

export type AnnouncerProps = {
    children: ReactNode;
};

/**
 * The page's single polite live region (ACCESSIBILITY.md: one announcer, not one per
 * component). useAnnounce() speaks through it: filters applied, a sort changed, a save done.
 * The region is emptied first, so the same message twice is announced twice.
 */
export default function Announcer({ children }: AnnouncerProps) {
    const [message, setMessage] = useState('');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const announce = useCallback<Announce>((next) => {
        setMessage('');
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => setMessage(next), 100);
    }, []);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    return (
        <AnnounceContext.Provider value={announce}>
            {children}
            <div aria-live="polite" aria-atomic="true" className="sr-only">
                {message}
            </div>
        </AnnounceContext.Provider>
    );
}

export function useAnnounce(): Announce {
    return useContext(AnnounceContext);
}
