import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { withEmbeddedContext } from '../shopify-auth';

export default function Settings({ priceMultiplier, updateUrl }) {
    const { flash } = usePage().props;
    const { data, setData, put, processing, errors } = useForm({ price_multiplier: String(priceMultiplier) });
    const [successDismissed, setSuccessDismissed] = useState(false);
    const [errorsDismissed, setErrorsDismissed] = useState(false);
    const errorMessages = JSON.stringify(errors);

    useEffect(() => setSuccessDismissed(false), [flash?.success]);
    useEffect(() => setErrorsDismissed(false), [errorMessages]);

    function save() {
        put(withEmbeddedContext(updateUrl), {
            preserveScroll: true,
            onSuccess: () => setSuccessDismissed(false),
            onError: () => setErrorsDismissed(false),
        });
    }

    return (
        <>
            <Head title="Settings" />
            <s-page heading="Settings" inlineSize="base">
                <s-button slot="primary-action" variant="primary" loading={processing} disabled={processing} onClick={save}>Save settings</s-button>
                {flash?.success && !successDismissed && (
                    <s-banner tone="success" dismissible onDismiss={() => setSuccessDismissed(true)}>{flash.success}</s-banner>
                )}
                {Object.keys(errors).length > 0 && !errorsDismissed && (
                    <s-banner tone="critical" heading="Check the settings" dismissible onDismiss={() => setErrorsDismissed(true)}>
                        <s-stack direction="block" gap="tight">
                            {Object.entries(errors).map(([field, message]) => <s-text key={field}>{message}</s-text>)}
                        </s-stack>
                    </s-banner>
                )}
                <s-section heading="Pricing">
                    <s-stack direction="block" gap="base">
                        <s-number-field
                            label="Store price multiplier"
                            value={data.price_multiplier}
                            min={1}
                            step={1}
                            suffix="x"
                            error={errors.price_multiplier}
                            onChange={(event) => setData('price_multiplier', event.currentTarget.value)}
                        />
                        <s-paragraph>The total of the surface, size, and selected add-ons is multiplied by this number for each Shopify variant. Save a configuration after changing this value to update its Shopify prices.</s-paragraph>
                    </s-stack>
                </s-section>
            </s-page>
        </>
    );
}
