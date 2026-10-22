import { useCallback, useRef, useState } from 'react';
import { sendJson, type ApiErrorBody } from '@/lib/api';
import { useTranslate } from '@/lib/i18n';

export type ApiActionResult<T> =
    | { ok: true; data: T }
    | { ok: false; status: number; message: string; code: string | null; fieldErrors: Record<string, string> };

export type ApiAction<T> = {
    /** Sends the request; while it runs, `pending` is true and a second call returns the first one's answer. */
    run: (method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', url: string, body?: unknown) => Promise<ApiActionResult<T>>;
    pending: boolean;
    /** The last failure in words, for an alert; null after a success or a reset. */
    error: string | null;
    /** 422 field errors: field => its first message, from the lang file on the server. */
    fieldErrors: Record<string, string>;
    reset: () => void;
};

/**
 * A write to the portal's JSON API (ARCHITECTURE.md §8) for the admin pages: CSRF handled by
 * sendJson, the control disabled the moment it is used (pending is set before the request
 * leaves, and a second call while pending is not sent), and every failure turned into words:
 *
 * - 422: the field errors, and a summary line;
 * - 409: the domain refusal's message (written in the lang files on the server: `{ code, message }`);
 * - 419 / 401 / 403: the session ended or the action is not allowed;
 * - 429: too many requests;
 * - anything else, or no answer: a general message.
 */
export function useApiAction<T = unknown>(): ApiAction<T> {
    const t = useTranslate();
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const inFlight = useRef<Promise<ApiActionResult<T>> | null>(null);

    const describe = useCallback(
        (status: number, body: ApiErrorBody): string => {
            const message = typeof body.message === 'string' && body.message !== '' ? body.message : null;
            switch (true) {
                case status === 422:
                    return t('common.api.invalid');
                case status === 409:
                    return message ?? t('common.api.conflict');
                case status === 419 || status === 401 || status === 403:
                    return t('common.api.forbidden');
                case status === 429:
                    return t('common.api.too_many');
                default:
                    return t('common.api.error');
            }
        },
        [t],
    );

    const run = useCallback<ApiAction<T>['run']>(
        (method, url, body) => {
            if (inFlight.current !== null) {
                return inFlight.current;
            }
            setPending(true);
            setError(null);
            setFieldErrors({});

            const request = (async (): Promise<ApiActionResult<T>> => {
                try {
                    const response = await sendJson<T>(method, url, body);
                    if (response.ok) {
                        return { ok: true, data: response.data };
                    }
                    const fields = Object.fromEntries(
                        Object.entries(response.body.errors ?? {}).flatMap(([field, messages]) => (messages[0] === undefined ? [] : [[field, messages[0]]])),
                    );
                    const message = describe(response.status, response.body);
                    setError(message);
                    setFieldErrors(fields);

                    return { ok: false, status: response.status, message, code: response.body.code ?? null, fieldErrors: fields };
                } catch {
                    const message = t('common.api.error');
                    setError(message);

                    return { ok: false, status: 0, message, code: null, fieldErrors: {} };
                } finally {
                    inFlight.current = null;
                    setPending(false);
                }
            })();
            inFlight.current = request;

            return request;
        },
        [describe, t],
    );

    const reset = useCallback(() => {
        setError(null);
        setFieldErrors({});
    }, []);

    return { run, pending, error, fieldErrors, reset };
}
