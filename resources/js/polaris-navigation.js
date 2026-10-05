import { router } from '@inertiajs/react';
import { withEmbeddedContext } from './shopify-auth';

export function visitEmbedded(event, path) {
    event.preventDefault();
    router.visit(withEmbeddedContext(path));
}
