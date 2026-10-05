import { Head, Link, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { withEmbeddedContext } from '../shopify-auth';

export default function Catalog({ catalogSyncUrl, homeUrl }) {
    const { post, processing } = useHttp({});
    const [status, setStatus] = useState('idle');
    const [message, setMessage] = useState('');

    async function pullCatalog() {
        setStatus('pending');
        setMessage('Queueing catalog pull…');

        try {
            const payload = await post(withEmbeddedContext(catalogSyncUrl));
            setStatus('success');
            setMessage(payload?.message ?? 'Catalog pull queued. Watch the queue listener for completion.');
        } catch (error) {
            const responseStatus = error?.response?.status;
            setStatus('error');
            setMessage(
                [401, 403].includes(responseStatus)
                    ? 'Shopify authentication was rejected. Reload the app from Shopify Admin.'
                    : responseStatus === 419
                        ? 'The Laravel session expired. Reload the app.'
                        : responseStatus === 429
                            ? 'Too many requests. Wait a moment and retry.'
                            : 'Could not queue the catalog pull. Check the app connection and try again.',
            );
        }
    }

    return (
        <>
            <Head title="Catalog" />
            <main className="mx-auto max-w-3xl px-6 py-12">
                <section className="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                    <Link href={withEmbeddedContext(homeUrl)} className="text-sm font-medium text-emerald-700 hover:underline">
                        ← Home
                    </Link>
                    <h1 className="mt-5 text-3xl font-semibold text-slate-950">Catalog</h1>
                    <p className="mt-3 text-slate-600">
                        Update the shared collections, products, add-ons, and their relationships from 3d-frames.
                    </p>
                    <div className="mt-8">
                        <s-button variant="primary" loading={processing} disabled={processing} onClick={pullCatalog}>
                            Pull Catalog
                        </s-button>
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
