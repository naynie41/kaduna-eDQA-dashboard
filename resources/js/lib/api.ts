/** A JSON API answer that was not 2xx. `status` is the HTTP status (404 for an unknown record). */
export class ApiError extends Error {
    constructor(public readonly status: number) {
        super(`Request failed with status ${status}`);
    }
}

/**
 * GET one of the portal's JSON endpoints (ARCHITECTURE.md §8) with the session cookie. GETs need
 * no CSRF token. Aborts with the signal.
 */
export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        ...(signal === undefined ? {} : { signal }),
    });
    if (!response.ok) {
        throw new ApiError(response.status);
    }

    return (await response.json()) as T;
}
