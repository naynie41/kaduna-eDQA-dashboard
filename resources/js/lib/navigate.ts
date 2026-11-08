/**
 * A full page load to a URL: the session is gone, so Inertia's client state is no use. Its own
 * module so a test can stand in for it (jsdom cannot navigate).
 */
export function goTo(url: string): void {
    window.location.assign(url);
}
