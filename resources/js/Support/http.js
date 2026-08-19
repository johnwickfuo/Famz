/**
 * Small fetch helpers for the handful of endpoints that answer in JSON rather
 * than with an Inertia page.
 *
 * Inertia's router is the right tool for anything that changes what is on
 * screen. It is the wrong tool for a progress ping that fires every ten seconds
 * and has nothing to render, which is what these are for.
 */

/**
 * Laravel's CSRF token, as the framework leaves it for us.
 *
 * It arrives in a cookie rather than a meta tag, so it survives a page served
 * from a cache — which a meta tag baked into HTML would not.
 */
export function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function postJson(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Request failed with ${response.status}`);
    }

    return response.json();
}

export async function getJson(url) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`Request failed with ${response.status}`);
    }

    return response.json();
}
