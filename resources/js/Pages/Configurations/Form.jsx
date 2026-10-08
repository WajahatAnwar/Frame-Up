import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { authenticatedFetch, withEmbeddedContext } from '../../shopify-auth';
import { visitEmbedded } from '../../polaris-navigation';

function emptyPrintType() {
    return {
        key: `${Date.now()}-${Math.random()}`,
        collection_id: '',
        product_id: '',
        variant_ids: [],
        addon_ids: [],
    };
}

function initialPrintTypes(configuration) {
    return (configuration?.print_types ?? []).map((printType) => ({
        key: printType.id,
        collection_id: printType.collection_id ?? '',
        product_id: printType.product_id ?? '',
        variant_ids: [...new Set((printType.selected_variant_ids ?? []).map(Number))],
        addon_ids: [...new Set((printType.selected_addon_ids ?? []).map(Number))],
    }));
}

function toggleId(ids, id, checked) {
    return checked ? [...new Set([...ids, Number(id)])] : ids.filter((existing) => Number(existing) !== Number(id));
}

function AddonChoices({ label, addons, selectedIds, disabled, onToggle }) {
    if (addons.length === 0) return null;

    return (
        <s-stack direction="block" gap="tight">
            <s-heading>{label}</s-heading>
            <s-stack direction="inline" gap="base">
                {addons.map((addon) => (
                    <s-checkbox
                        key={addon.id}
                        label={addon.title}
                        checked={selectedIds.includes(addon.id)}
                        disabled={disabled}
                        onChange={(event) => onToggle(addon.id, event.currentTarget.checked)}
                    />
                ))}
            </s-stack>
        </s-stack>
    );
}

export default function ConfigurationForm({ mode, configuration, catalog, submitUrl, editUrl, deleteUrl, indexUrl, pricingPreviewUrl, settingsUrl, priceMultiplier, productTypesUrl }) {
    const readOnly = mode === 'show';
    const { flash } = usePage().props;
    const { data, setData, post, transform, processing, errors } = useForm({
        shopify_product_type: configuration?.shopify_product_type ?? '',
        status: configuration?.status ?? 'draft',
        print_types: initialPrintTypes(configuration),
    });
    const [priceSummaries, setPriceSummaries] = useState([]);
    const [priceError, setPriceError] = useState('');
    const [productTypes, setProductTypes] = useState(configuration?.shopify_product_type ? [configuration.shopify_product_type] : []);
    const [productTypesError, setProductTypesError] = useState('');
    const [productTypesLoading, setProductTypesLoading] = useState(mode !== 'show');
    const [successDismissed, setSuccessDismissed] = useState(false);
    const [errorsDismissed, setErrorsDismissed] = useState(false);
    const [addedPrintTypeKey, setAddedPrintTypeKey] = useState(null);
    const addedPrintTypeRef = useRef(null);
    const errorMessages = JSON.stringify(errors);
    const priceSelections = JSON.stringify(data.print_types);

    useEffect(() => setSuccessDismissed(false), [flash?.success]);
    useEffect(() => setErrorsDismissed(false), [errorMessages]);
    useEffect(() => {
        if (!addedPrintTypeKey || !addedPrintTypeRef.current) return;

        addedPrintTypeRef.current.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'start',
        });
    }, [addedPrintTypeKey]);

    useEffect(() => {
        if (readOnly) return;

        const controller = new AbortController();
        authenticatedFetch(productTypesUrl, { signal: controller.signal })
            .then(async (response) => {
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Shopify product types could not be loaded.');
                setProductTypes([...new Set([...(result.product_types ?? []), configuration?.shopify_product_type].filter(Boolean))]);
                setProductTypesError('');
            })
            .catch((error) => {
                if (error.name !== 'AbortError') setProductTypesError(error.message || 'Shopify product types could not be loaded.');
            })
            .finally(() => { if (!controller.signal.aborted) setProductTypesLoading(false); });

        return () => controller.abort();
    }, [configuration?.shopify_product_type, productTypesUrl, readOnly]);

    useEffect(() => {
        const controller = new AbortController();
        const selections = JSON.parse(priceSelections);
        if (!selections.some((row) => row.product_id && row.variant_ids.length > 0)) {
            setPriceSummaries([]);
            setPriceError('');
            return () => controller.abort();
        }

        const timer = setTimeout(async () => {
            try {
                const response = await authenticatedFetch(pricingPreviewUrl, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        shopify_product_type: data.shopify_product_type || 'Price preview',
                        status: 'draft',
                        print_types: selections,
                    }),
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Prices could not be calculated.');
                setPriceSummaries(result.print_types ?? []);
                setPriceError('');
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setPriceSummaries([]);
                    setPriceError(error.message || 'Prices could not be calculated.');
                }
            }
        }, 350);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [priceSelections, pricingPreviewUrl, data.shopify_product_type]);

    function updatePrintType(index, changes) {
        setData('print_types', data.print_types.map((printType, current) => current === index ? { ...printType, ...changes } : printType));
    }

    function addPrintType() {
        const printType = emptyPrintType();
        setData('print_types', [...data.print_types, printType]);
        setAddedPrintTypeKey(printType.key);
    }

    function save() {
        const url = withEmbeddedContext(submitUrl);
        transform((values) => mode === 'create' ? values : { ...values, _method: 'put' });
        post(url, {
            preserveScroll: true,
            onSuccess: () => setSuccessDismissed(false),
            onError: () => setErrorsDismissed(false),
        });
    }

    function remove() {
        router.delete(withEmbeddedContext(deleteUrl));
    }

    const heading = mode === 'create' ? 'Create configuration' : mode === 'edit' ? 'Edit configuration' : configuration.shopify_product_type;

    return (
        <>
            <Head title={heading} />
            <s-page heading={heading} inlineSize="base">
                <s-link slot="breadcrumb-actions" href={withEmbeddedContext(indexUrl)} onClick={(event) => visitEmbedded(event, indexUrl)}>Configurations</s-link>
                {mode === 'show' && <s-badge slot="accessory" tone={data.status === 'active' ? 'success' : 'info'}>{data.status}</s-badge>}
                {readOnly ? (
                    <s-button slot="primary-action" variant="primary" href={withEmbeddedContext(editUrl)} onClick={(event) => visitEmbedded(event, editUrl)}>Edit configuration</s-button>
                ) : (
                    <s-button slot="primary-action" variant="primary" loading={processing} disabled={processing} onClick={save}>
                        Save configuration
                    </s-button>
                )}
                {configuration && (
                    <s-button slot="secondary-actions" tone="critical" commandFor="delete-configuration-modal" command="--show">
                        Delete
                    </s-button>
                )}

                {flash?.success && !successDismissed && (
                    <s-banner tone="success" dismissible onDismiss={() => setSuccessDismissed(true)}>{flash.success}</s-banner>
                )}

                {Object.keys(errors).length > 0 && !errorsDismissed && (
                    <s-banner tone="critical" heading="Check the configuration" dismissible onDismiss={() => setErrorsDismissed(true)}>
                        <s-stack direction="block" gap="tight">
                            {Object.entries(errors).map(([field, message]) => <s-text key={field}>{message}</s-text>)}
                        </s-stack>
                    </s-banner>
                )}

                {productTypesError && <s-banner tone="critical" dismissible onDismiss={() => setProductTypesError('')}>{productTypesError}</s-banner>}

                <s-section heading="1. Product basic details">
                    <s-stack direction="block" gap="base">
                        <s-select
                            label="Target Shopify product type"
                            value={data.shopify_product_type}
                            disabled={readOnly || productTypesLoading}
                            error={errors.shopify_product_type}
                            onChange={(event) => setData('shopify_product_type', event.currentTarget.value)}
                        >
                            <s-option value="">{productTypesLoading ? 'Loading product types…' : 'Choose a product type'}</s-option>
                            {productTypes.map((type) => <s-option key={type} value={type}>{type}</s-option>)}
                        </s-select>
                        <s-paragraph>The configuration will update products in the selected Shopify product type.</s-paragraph>
                        <s-select label="Status" value={data.status} disabled={readOnly} onChange={(event) => setData('status', event.currentTarget.value)}>
                            <s-option value="draft">Draft</s-option>
                            <s-option value="active">Active</s-option>
                        </s-select>
                        <s-paragraph>Active configurations update matching Shopify products when saved. Drafts leave Shopify products as they are.</s-paragraph>
                    </s-stack>
                </s-section>

                <s-section heading="2. Print types" subheading="Add the print choices available for this Shopify product type.">
                    <s-stack direction="block" gap="large">
                        {data.print_types.length > 0 && (
                            <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                                <s-text tone="subdued">{data.print_types.length} {data.print_types.length === 1 ? 'print type' : 'print types'} configured</s-text>
                                {!readOnly && <s-button variant="primary" disabled={processing} onClick={addPrintType}>Add print type</s-button>}
                            </s-stack>
                        )}
                        {data.print_types.length === 0 && (
                            <s-box padding="base" background="subdued" borderRadius="base">
                                <s-stack direction="block" gap="base">
                                    <s-heading>{readOnly ? 'No print types added' : 'Add your first print type'}</s-heading>
                                    <s-paragraph>Choose a print type and surface, then select the sizes and add-ons available to customers.</s-paragraph>
                                    {!readOnly && <s-button variant="primary" disabled={processing} onClick={addPrintType}>Add print type</s-button>}
                                </s-stack>
                            </s-box>
                        )}

                        {data.print_types.map((printType, index) => {
                            const collection = catalog.find((item) => item.id === Number(printType.collection_id));
                            const surface = collection?.surfaces.find((item) => item.id === Number(printType.product_id));
                            const basic = surface?.addons.filter((addon) => addon.category === 'basic') ?? [];
                            const advance = surface?.addons.filter((addon) => addon.category === 'advance') ?? [];
                            const other = surface?.addons.filter((addon) => addon.category === 'other') ?? [];
                            const exclusive = (surface?.addons.filter((addon) => addon.category === 'exclusive') ?? [])
                                .reduce((groups, addon) => ({ ...groups, [addon.group]: [...(groups[addon.group] ?? []), addon] }), {});
                            const priceSummary = priceSummaries.find((summary) => summary.index === index);

                            return (
                                <s-box key={printType.key} ref={printType.key === addedPrintTypeKey ? addedPrintTypeRef : undefined} borderWidth="base" borderColor="base" borderRadius="large" overflow="hidden">
                                    <s-box padding="base" background="subdued">
                                        <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                                            <s-stack direction="block" gap="tight">
                                                <s-badge>Print type {index + 1}</s-badge>
                                                <s-heading>{collection?.title || 'New print type'}</s-heading>
                                                <s-text tone="subdued">{surface?.title || 'Choose a print type and surface below.'}</s-text>
                                            </s-stack>
                                            {!readOnly && (
                                                <s-button variant="tertiary" tone="critical" disabled={processing} onClick={() => setData('print_types', data.print_types.filter((_, current) => current !== index))}>
                                                    Remove print type
                                                </s-button>
                                            )}
                                        </s-stack>
                                    </s-box>
                                    <s-box padding="base">
                                        <s-stack direction="block" gap="large">
                                            <s-stack direction="block" gap="base">
                                                <s-heading>1. Print type and surface</s-heading>
                                                <s-stack direction="block" gap="base">
                                                    <s-select
                                                        label="Print type"
                                                        value={String(printType.collection_id)}
                                                        disabled={readOnly}
                                                        error={errors[`print_types.${index}.collection_id`]}
                                                        onChange={(event) => updatePrintType(index, {
                                                            collection_id: event.currentTarget.value ? Number(event.currentTarget.value) : '',
                                                            product_id: '', variant_ids: [], addon_ids: [],
                                                        })}
                                                    >
                                                        <s-option value="">Choose a print type</s-option>
                                                        {catalog.map((item) => <s-option key={item.id} value={String(item.id)}>{item.title}</s-option>)}
                                                    </s-select>
                                                    <s-select
                                                        label="Surface"
                                                        value={String(printType.product_id)}
                                                        disabled={readOnly || !collection}
                                                        error={errors[`print_types.${index}.product_id`]}
                                                        onChange={(event) => updatePrintType(index, {
                                                            product_id: event.currentTarget.value ? Number(event.currentTarget.value) : '',
                                                            variant_ids: [], addon_ids: [],
                                                        })}
                                                    >
                                                        <s-option value="">Choose a surface</s-option>
                                                        {collection?.surfaces.map((item) => {
                                                            const usedByAnotherPrintType = data.print_types.some((other, otherIndex) =>
                                                                otherIndex !== index && Number(other.product_id) === Number(item.id),
                                                            );

                                                            return (
                                                                <s-option key={item.id} value={String(item.id)} disabled={usedByAnotherPrintType}>
                                                                    {item.title}{usedByAnotherPrintType ? ' (already selected)' : ''}
                                                                </s-option>
                                                            );
                                                        })}
                                                    </s-select>
                                                </s-stack>
                                            </s-stack>

                                            <s-stack direction="block" gap="base">
                                                <s-heading>2. Preset sizes</s-heading>
                                                {surface ? (
                                                    <s-stack direction="block" gap="tight">
                                                        {surface.variants.length === 0 && <s-paragraph>No predefined sizes are available for this surface.</s-paragraph>}
                                                        <s-stack direction="inline" gap="base">
                                                            {surface.variants.map((variant) => (
                                                                <s-checkbox
                                                                    key={variant.id}
                                                                    label={variant.title || `${variant.width} × ${variant.height}`}
                                                                    checked={printType.variant_ids.includes(variant.id)}
                                                                    disabled={readOnly}
                                                                    onChange={(event) => updatePrintType(index, {
                                                                        variant_ids: toggleId(printType.variant_ids, variant.id, event.currentTarget.checked),
                                                                    })}
                                                                />
                                                            ))}
                                                        </s-stack>
                                                    </s-stack>
                                                ) : <s-paragraph>Choose a surface to see its preset sizes.</s-paragraph>}
                                            </s-stack>

                                            <s-stack direction="block" gap="base">
                                                <s-heading>3. Customer add-ons</s-heading>
                                                {surface ? (
                                                    <s-stack direction="block" gap="base">
                                                        {surface.addons.length === 0 && <s-paragraph>No add-ons are available for this surface.</s-paragraph>}
                                                        <AddonChoices label="Basic · customers may choose multiple" addons={basic} selectedIds={printType.addon_ids} disabled={readOnly} onToggle={(id, checked) => updatePrintType(index, { addon_ids: toggleId(printType.addon_ids, id, checked) })} />
                                                        <AddonChoices label="Advance · customers choose one" addons={advance} selectedIds={printType.addon_ids} disabled={readOnly} onToggle={(id, checked) => updatePrintType(index, { addon_ids: toggleId(printType.addon_ids, id, checked) })} />
                                                        <AddonChoices label="Other related add-ons" addons={other} selectedIds={printType.addon_ids} disabled={readOnly} onToggle={(id, checked) => updatePrintType(index, { addon_ids: toggleId(printType.addon_ids, id, checked) })} />
                                                        {Object.entries(exclusive).map(([group, addons]) => (
                                                            <AddonChoices key={group} label={`${group} · offer at least one option`} addons={addons} selectedIds={printType.addon_ids} disabled={readOnly} onToggle={(id, checked) => updatePrintType(index, { addon_ids: toggleId(printType.addon_ids, id, checked) })} />
                                                        ))}
                                                    </s-stack>
                                                ) : <s-paragraph>Choose a surface to see its add-ons.</s-paragraph>}
                                            </s-stack>
                                            <s-box padding="base" background="subdued" borderRadius="base">
                                                <s-stack direction="block" gap="base">
                                                    <s-heading>4. Total selling price</s-heading>
                                                    {priceSummary ? (
                                                        <s-stack direction="block" gap="tight">
                                                            <s-heading>{priceSummary.min_price === priceSummary.max_price
                                                                ? priceSummary.min_price
                                                                : `${priceSummary.min_price} – ${priceSummary.max_price}`}</s-heading>
                                                            <s-paragraph>Across {priceSummary.variant_count} selected size and mount {priceSummary.variant_count === 1 ? 'combination' : 'combinations'}, including add-ons and the {priceMultiplier}x store multiplier.</s-paragraph>
                                                        </s-stack>
                                                    ) : <s-paragraph>{priceError || 'Select a priced size to see the total.'}</s-paragraph>}
                                                </s-stack>
                                            </s-box>
                                        </s-stack>
                                    </s-box>
                                </s-box>
                            );
                        })}
                        {!readOnly && data.print_types.length > 0 && (
                            <s-box padding="base" background="subdued" borderRadius="base">
                                <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                                    <s-text>Add another print type or surface to this configuration.</s-text>
                                    <s-button disabled={processing} onClick={addPrintType}>Add print type</s-button>
                                </s-stack>
                            </s-box>
                        )}
                    </s-stack>
                </s-section>

                <s-section heading="3. Pricing">
                    <s-link slot="secondary-actions" href={withEmbeddedContext(settingsUrl)} onClick={(event) => visitEmbedded(event, settingsUrl)}>Edit in Settings</s-link>
                    <s-stack direction="block" gap="tight">
                        <s-paragraph>Store price multiplier: <s-badge>{priceMultiplier}x</s-badge></s-paragraph>
                        <s-paragraph>Each Shopify variant uses its surface, size, and applicable add-on total multiplied by this value.</s-paragraph>
                    </s-stack>
                </s-section>

                {!readOnly && <s-button variant="primary" loading={processing} disabled={processing} onClick={save}>Save configuration</s-button>}

                {configuration && (
                    <s-modal id="delete-configuration-modal" heading="Delete configuration?">
                        <s-paragraph>Delete the “{configuration.shopify_product_type}” configuration and its print type selections? This action cannot be undone.</s-paragraph>
                        <s-button slot="primary-action" variant="primary" tone="critical" disabled={processing} onClick={remove}>Delete configuration</s-button>
                        <s-button slot="secondary-actions" commandFor="delete-configuration-modal" command="--hide">Cancel</s-button>
                    </s-modal>
                )}
            </s-page>
        </>
    );
}
