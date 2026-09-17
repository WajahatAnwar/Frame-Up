import { http } from '@inertiajs/core';

const contextKeys = ['shop', 'host'];

export async function getShopifySessionToken() {
    if (!window.shopify?.idToken) {
        throw new Error('Shopify App Bridge is unavailable. Open the app from Shopify Admin.');
    }

    return window.shopify.idToken();
}

export function withEmbeddedContext(path) {
    const url = new URL(path, window.location.origin);
    const current = new URLSearchParams(window.location.search);

    contextKeys.forEach((key) => {
        const value = current.get(key);
        if (value) url.searchParams.set(key, value);
    });

    return `${url.pathname}${url.search}`;
}

export async function authenticatedFetch(path, options = {}) {
    const token = await getShopifySessionToken();

    return fetch(withEmbeddedContext(path), {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...options.headers,
            Authorization: `Bearer ${token}`,
        },
    });
}

// Inertia v3 runs this hook immediately before every visit request, so each
// navigation receives a fresh App Bridge session token.
http.onRequest(async (config) => {
    const token = await getShopifySessionToken();

    return {
        ...config,
        headers: {
            ...config.headers,
            Authorization: `Bearer ${token}`,
            'X-Requested-With': 'XMLHttpRequest',
        },
    };
});
