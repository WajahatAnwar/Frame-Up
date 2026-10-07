import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { withEmbeddedContext } from '../../shopify-auth';
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

export default function ConfigurationForm({ mode, configuration, catalog, submitUrl, editUrl, deleteUrl, indexUrl }) {
    const readOnly = mode === 'show';
    const { data, setData, post, transform, processing, errors } = useForm({
        name: configuration?.name ?? '',
        shopify_product_type: configuration?.shopify_product_type ?? '',
        status: configuration?.status ?? 'draft',
        image: null,
        print_types: initialPrintTypes(configuration),
    });
    const [imagePreview, setImagePreview] = useState(configuration?.image_url ?? null);
    const [imageError, setImageError] = useState('');

    useEffect(() => {
        if (!data.image) {
            setImagePreview(configuration?.image_url ?? null);
            return;
        }

        const objectUrl = URL.createObjectURL(data.image);
        setImagePreview(objectUrl);
        return () => URL.revokeObjectURL(objectUrl);
    }, [data.image, configuration?.image_url]);

    function updatePrintType(index, changes) {
        setData('print_types', data.print_types.map((printType, current) => current === index ? { ...printType, ...changes } : printType));
    }

    function save() {
        const url = withEmbeddedContext(submitUrl);
        transform((values) => mode === 'create' ? values : { ...values, _method: 'put' });
        post(url, { preserveScroll: true, forceFormData: true });
    }

    function selectImage(event) {
        const file = event.currentTarget.files?.[0] ?? null;
        if (file && file.size > 5 * 1024 * 1024) {
            setImageError('Choose an image smaller than 5 MB.');
            setData('image', null);
            event.currentTarget.value = '';
            return;
        }

        setImageError('');
        setData('image', file);
    }

    function remove() {
        router.delete(withEmbeddedContext(deleteUrl));
    }

    const heading = mode === 'create' ? 'Create configuration' : mode === 'edit' ? 'Edit configuration' : configuration.name;

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

                {Object.keys(errors).length > 0 && (
                    <s-banner tone="critical" heading="Check the configuration">
                        <s-stack direction="block" gap="tight">
                            {Object.entries(errors).map(([field, message]) => <s-text key={field}>{message}</s-text>)}
                        </s-stack>
                    </s-banner>
                )}

                <s-section heading="1. Product basic details">
                    <s-stack direction="block" gap="base">
                        <s-text-field
                            label="Configuration name"
                            value={data.name}
                            disabled={readOnly}
                            error={errors.name}
                            onChange={(event) => setData('name', event.currentTarget.value)}
                        />
                        <s-text-field
                            label="Shopify product type"
                            value={data.shopify_product_type}
                            disabled={readOnly}
                            error={errors.shopify_product_type}
                            onChange={(event) => setData('shopify_product_type', event.currentTarget.value)}
                        />
                        <s-select label="Status" value={data.status} disabled={readOnly} onChange={(event) => setData('status', event.currentTarget.value)}>
                            <s-option value="draft">Draft</s-option>
                            <s-option value="active">Active</s-option>
                        </s-select>
                        <s-section heading="Product image">
                            <s-stack direction="block" gap="base">
                                {imagePreview && <s-thumbnail src={imagePreview} alt={`${data.name || 'Configuration'} product image`} size="large" />}
                                {!readOnly && (
                                    <s-drop-zone
                                        label={configuration?.image_path ? 'Replace product image' : 'Add product image'}
                                        accessibilityLabel="Choose a JPEG, PNG, or WebP product image"
                                        accept="image/jpeg,image/png,image/webp"
                                        disabled={processing}
                                        error={imageError || errors.image}
                                        onChange={selectImage}
                                        onDropRejected={() => setImageError('Choose a JPEG, PNG, or WebP image.')}
                                    />
                                )}
                                {data.image && <s-text>{data.image.name}</s-text>}
                                {!imagePreview && readOnly && <s-paragraph>No product image selected.</s-paragraph>}
                                {!readOnly && <s-paragraph>Optional. JPEG, PNG, or WebP, up to 5 MB. This becomes the first Shopify product image.</s-paragraph>}
                            </s-stack>
                        </s-section>
                        <s-paragraph>Active configurations require a surface, a preset size, and at least one option in every exclusive group.</s-paragraph>
                    </s-stack>
                </s-section>

                {configuration && (
                    <s-section heading="Shopify product">
                        <s-paragraph>
                            {configuration.shopify_product_id
                                ? `Linked product: ${configuration.shopify_product_id}`
                                : 'No Shopify product yet. Complete a print type with a priced preset size, then save to create one.'}
                        </s-paragraph>
                    </s-section>
                )}

                <s-section heading="2. Print types" subheading="Add the print choices available for this Shopify product type.">
                    <s-stack direction="block" gap="base">
                        {data.print_types.length === 0 && <s-paragraph>No print types added yet.</s-paragraph>}
                        {!readOnly && <s-button onClick={() => setData('print_types', [...data.print_types, emptyPrintType()])}>Add print type</s-button>}
                    </s-stack>
                </s-section>

                {data.print_types.map((printType, index) => {
                    const collection = catalog.find((item) => item.id === Number(printType.collection_id));
                    const surface = collection?.surfaces.find((item) => item.id === Number(printType.product_id));
                    const basic = surface?.addons.filter((addon) => addon.category === 'basic') ?? [];
                    const advance = surface?.addons.filter((addon) => addon.category === 'advance') ?? [];
                    const other = surface?.addons.filter((addon) => addon.category === 'other') ?? [];
                    const exclusive = (surface?.addons.filter((addon) => addon.category === 'exclusive') ?? [])
                        .reduce((groups, addon) => ({ ...groups, [addon.group]: [...(groups[addon.group] ?? []), addon] }), {});

                    return (
                        <s-section key={printType.key} heading={`Print type ${index + 1}${collection ? ` · ${collection.title}` : ''}`} subheading={surface?.title || 'Choose a print type and surface to set its options.'}>
                            {!readOnly && (
                                <s-button slot="secondary-actions" tone="critical" onClick={() => setData('print_types', data.print_types.filter((_, current) => current !== index))}>
                                    Remove
                                </s-button>
                            )}
                            <s-stack direction="block" gap="base">
                                <s-section heading="Selection">
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
                                </s-section>

                                <s-section heading="Available preset sizes">
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
                                </s-section>

                                <s-section heading="Add-ons offered to customers">
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
                                </s-section>
                            </s-stack>
                        </s-section>
                    );
                })}

                {!readOnly && <s-button variant="primary" loading={processing} disabled={processing} onClick={save}>Save configuration</s-button>}

                {configuration && (
                    <s-modal id="delete-configuration-modal" heading="Delete configuration?">
                        <s-paragraph>Delete “{configuration.name}” and its print type selections? This action cannot be undone.</s-paragraph>
                        <s-button slot="primary-action" variant="primary" tone="critical" disabled={processing} onClick={remove}>Delete configuration</s-button>
                        <s-button slot="secondary-actions" commandFor="delete-configuration-modal" command="--hide">Cancel</s-button>
                    </s-modal>
                )}
            </s-page>
        </>
    );
}
