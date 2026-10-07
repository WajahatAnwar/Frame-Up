import { Head } from '@inertiajs/react';

export default function Plans() {
    return (
        <>
            <Head title="Plans" />
            <s-page heading="Plans" inlineSize="base">
                <s-section heading="Current plan">
                    <s-stack direction="block" gap="base">
                        <s-stack direction="inline" gap="base" alignItems="center">
                            <s-heading>Free plan</s-heading>
                            <s-badge tone="success">Subscribed</s-badge>
                        </s-stack>
                        <s-paragraph>Free plan subscribed.</s-paragraph>
                    </s-stack>
                </s-section>
            </s-page>
        </>
    );
}
