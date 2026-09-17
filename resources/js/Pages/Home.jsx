import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { authenticatedFetch } from '../shopify-auth';

export default function Home({ appName, shop, temporaryAuthCheckUrl }) {
    const [status, setStatus] = useState('idle');
    const [message, setMessage] = useState('');

    async function testAuthenticatedRoute() {
        setStatus('pending');
        setMessage('Checking Shopify authentication…');

        try {
            const response = await authenticatedFetch(temporaryAuthCheckUrl);

            if ([401, 403].includes(response.status)) {
                throw new Error('Shopify authentication was rejected. Reload the app from Shopify Admin.');
            }
            if (response.status === 419) throw new Error('The Laravel session expired. Reload the app.');
            if (response.status === 429) throw new Error('Too many requests. Wait a moment and retry.');
            if (!response.ok) throw new Error('The authenticated request failed.');

            const payload = await response.json();
            setStatus('success');
            setMessage(`${payload.message} (${payload.shop})`);
        } catch (error) {
            setStatus('error');
            setMessage(error instanceof TypeError ? 'Network error. Check the app connection.' : error.message);
        }
    }

    return (
        <>
            <Head title="Home" />
            <main className="mx-auto max-w-3xl px-6 py-12">
                <section className="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                    <p className="text-sm font-semibold uppercase tracking-wide text-emerald-700">Embedded Shopify app</p>
                    <h1 className="mt-2 text-3xl font-semibold text-slate-950">{appName}</h1>
                    <p className="mt-3 text-slate-600">
                        Inertia React is authenticated for <strong>{shop.domain}</strong>.
                    </p>

                    <div className="mt-8">
                        <s-stack direction="inline" gap="base">
                            <s-button
                                variant="primary"
                                loading={status === 'pending'}
                                onClick={testAuthenticatedRoute}
                            >
                                Test authenticated route
                            </s-button>
                            <s-button
                                variant="secondary"
                                onClick={() => router.reload({ preserveScroll: true })}
                            >
                                Test Inertia reload
                            </s-button>
                        </s-stack>
                    </div>

                    <p
                        role="status"
                        aria-live="polite"
                        className={`mt-5 min-h-6 ${status === 'error' ? 'text-red-700' : 'text-slate-700'}`}
                    >
                        {message}
                    </p>
                </section>
            </main>
        </>
    );
}
