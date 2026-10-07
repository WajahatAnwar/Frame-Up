import '../css/app.css';
import './shopify-auth';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { withEmbeddedContext } from './shopify-auth';
import { visitEmbedded } from './polaris-navigation';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <>
                <s-app-nav>
                    <s-link href={withEmbeddedContext('/')} rel="home" onClick={(event) => visitEmbedded(event, '/')}>Dashboard</s-link>
                    <s-link href={withEmbeddedContext('/configurations')} onClick={(event) => visitEmbedded(event, '/configurations')}>Configurations</s-link>
                    <s-link href={withEmbeddedContext('/settings')} onClick={(event) => visitEmbedded(event, '/settings')}>Settings</s-link>
                    <s-link href={withEmbeddedContext('/plans')} onClick={(event) => visitEmbedded(event, '/plans')}>Plans</s-link>
                </s-app-nav>
                <App {...props} />
            </>,
        );
    },
    progress: {
        color: '#0f172a',
    },
});
