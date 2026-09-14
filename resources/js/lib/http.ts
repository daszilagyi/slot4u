/**
 * The Laravel XSRF cookie, as the header a same-origin request has to carry.
 * Laravel sets it on every response; Inertia sends it on its own visits, so only
 * a request outside Inertia needs this.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * POST a form to a JSON endpoint of this app, outside an Inertia visit — for a
 * request whose answer is data for the page (a live preview), not a new page.
 * Throws on a non-2xx answer so the caller has one failure path.
 */
export async function postFormJson<T>(url: string, body: FormData): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    return (await response.json()) as T;
}
