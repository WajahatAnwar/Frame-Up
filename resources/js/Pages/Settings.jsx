import { Head, useForm, usePage } from '@inertiajs/react';
import { withEmbeddedContext } from '../shopify-auth';

export default function Settings({ priceMultiplier, updateUrl }) {
    const { flash } = usePage().props;
    const { data, setData, put, processing, errors } = useForm({ price_multiplier: String(priceMultiplier) });

    function save() {
        put(withEmbeddedContext(updateUrl), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Settings" />
            <s-page heading="Settings" inlineSize="base">
                <s-button slot="primary-action" variant="primary" loading={processing} disabled={processing} onClick={save}>Save settings</s-button>
                {flash?.success && <s-banner tone="success">{flash.success}</s-banner>}
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
