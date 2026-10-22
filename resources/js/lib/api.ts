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

/** What a non-2xx answer said: Laravel's 422 `{ message, errors }`, or a domain 409 `{ code, message }`. */
export type ApiErrorBody = {
    message?: string;
    code?: string;
    errors?: Record<string, string[]>;
};

export type ApiResponse<T> = { ok: true; status: number; data: T } | { ok: false; status: number; body: ApiErrorBody };

/** Laravel's XSRF-TOKEN cookie (readable by design), sent back as X-XSRF-TOKEN. */
function xsrfToken(): string | null {
    const match = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));

    return match === undefined ? null : decodeURIComponent(match.slice('XSRF-TOKEN='.length));
}

/**
 * POST, PATCH, PUT or DELETE to a JSON endpoint with the session cookie and the CSRF token.
 * Never throws for an HTTP status: the answer says ok or not, with the error body to show.
 */
export async function sendJson<T>(method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', url: string, body?: unknown, signal?: AbortSignal): Promise<ApiResponse<T>> {
    const token = xsrfToken();
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        ...(signal === undefined ? {} : { signal }),
    });
    const parsed: unknown = response.status === 204 ? null : await response.json().catch(() => null);

    return response.ok
        ? { ok: true, status: response.status, data: parsed as T }
        : { ok: false, status: response.status, body: (parsed ?? {}) as ApiErrorBody };
}
