import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { withEmbeddedContext } from '../../shopify-auth';
import { visitEmbedded } from '../../polaris-navigation';

export default function ConfigurationsIndex({ configurations, filters, indexUrl, createUrl }) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);

    function applyFilters() {
        router.get(withEmbeddedContext(indexUrl), { search, status }, { preserveState: true, replace: true });
    }

    function clearFilters() {
        setSearch('');
        setStatus('');
        router.get(withEmbeddedContext(indexUrl), {}, { preserveState: true, replace: true });
    }

    const hasFilters = Boolean(filters.search || filters.status);

    return (
        <>
            <Head title="Configurations" />
            <s-page heading="Configurations" inlineSize="base">
                <s-button slot="primary-action" variant="primary" href={withEmbeddedContext(createUrl)} onClick={(event) => visitEmbedded(event, createUrl)}>
                    Create configuration
                </s-button>
                <s-section heading="Find configurations" subheading="Search by Shopify product type, then narrow the results by status.">
                    <s-stack direction="inline" gap="base" alignItems="end">
                        <s-text-field label="Search by product type" value={search} onChange={(event) => setSearch(event.currentTarget.value)} onKeyDown={(event) => { if (event.key === 'Enter') applyFilters(); }} />
                        <s-select label="Status" value={status} onChange={(event) => setStatus(event.currentTarget.value)}>
                            <s-option value="">All statuses</s-option>
                            <s-option value="draft">Draft</s-option>
                            <s-option value="active">Active</s-option>
                        </s-select>
                        <s-button onClick={applyFilters}>Apply</s-button>
                        {hasFilters && <s-button variant="tertiary" onClick={clearFilters}>Clear</s-button>}
                    </s-stack>
                </s-section>

                <s-section heading="Configurations" subheading={`${configurations.total} ${configurations.total === 1 ? 'configuration' : 'configurations'} found`} padding="none">
                    <s-stack direction="block" gap="base">
                        {configurations.data.length === 0 ? (
                            <s-stack direction="block" gap="base" padding="base">
                                <s-paragraph>{hasFilters ? 'No configurations match your search or status filter.' : 'No configurations yet. Create one to set up print choices for your store.'}</s-paragraph>
                                {hasFilters
                                    ? <s-button onClick={clearFilters}>Clear filters</s-button>
                                    : <s-button variant="primary" href={withEmbeddedContext(createUrl)} onClick={(event) => visitEmbedded(event, createUrl)}>Create configuration</s-button>}
                            </s-stack>
                        ) : (
                            <s-table>
                                <s-table-header-row>
                                    <s-table-header listSlot="primary">Configuration</s-table-header>
                                    <s-table-header listSlot="labeled">Print types</s-table-header>
                                    <s-table-header listSlot="inline">Status</s-table-header>
                                    <s-table-header listSlot="inline">Action</s-table-header>
                                </s-table-header-row>
                                <s-table-body>
                                    {configurations.data.map((configuration) => (
                                        <s-table-row key={configuration.id}>
                                            <s-table-cell>
                                                <s-stack direction="block" gap="tight">
                                                    <s-link href={withEmbeddedContext(`/configurations/${configuration.id}`)} onClick={(event) => visitEmbedded(event, `/configurations/${configuration.id}`)}>{configuration.shopify_product_type}</s-link>
                                                </s-stack>
                                            </s-table-cell>
                                            <s-table-cell>{configuration.print_type_names.length === 0 ? 'None yet' : `${configuration.print_type_names.length} ${configuration.print_type_names.length === 1 ? 'print type' : 'print types'}`}</s-table-cell>
                                            <s-table-cell><s-badge tone={configuration.status === 'active' ? 'success' : 'info'}>{configuration.status === 'active' ? 'Active' : 'Draft'}</s-badge></s-table-cell>
                                            <s-table-cell><s-link href={withEmbeddedContext(`/configurations/${configuration.id}/edit`)} onClick={(event) => visitEmbedded(event, `/configurations/${configuration.id}/edit`)}>Edit</s-link></s-table-cell>
                                        </s-table-row>
                                    ))}
                                </s-table-body>
                            </s-table>
                        )}

                        {configurations.total > 0 && (
                            <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between" padding="base">
                                <s-text tone="subdued">Showing {configurations.from ?? 0}–{configurations.to ?? 0} of {configurations.total}</s-text>
                                {(configurations.prev_page_url || configurations.next_page_url) && (
                                    <s-stack direction="inline" gap="tight">
                                        <s-button disabled={!configurations.prev_page_url} onClick={() => router.visit(withEmbeddedContext(configurations.prev_page_url))}>Previous</s-button>
                                        <s-button disabled={!configurations.next_page_url} onClick={() => router.visit(withEmbeddedContext(configurations.next_page_url))}>Next</s-button>
                                    </s-stack>
                                )}
                            </s-stack>
                        )}
                    </s-stack>
                </s-section>
            </s-page>
        </>
    );
}
