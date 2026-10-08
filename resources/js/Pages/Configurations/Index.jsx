import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { withEmbeddedContext } from '../../shopify-auth';
import { visitEmbedded } from '../../polaris-navigation';

function formatUpdatedAt(value) {
    return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

export default function ConfigurationsIndex({ configurations, filters, statusCounts, printTypeOptions, indexUrl, createUrl }) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [printType, setPrintType] = useState(filters.print_type || 'none');
    const [sort, setSort] = useState(filters.sort);
    const searchTimer = useRef(null);
    const skipSearchDebounce = useRef(false);
    const previousFilters = useRef(filters);

    useEffect(() => {
        const previous = previousFilters.current;
        setSearch((current) => current === previous.search ? filters.search : current);
        setStatus((current) => current === previous.status ? filters.status : current);
        setPrintType((current) => current === (previous.print_type || 'none') ? (filters.print_type || 'none') : current);
        setSort((current) => current === previous.sort ? filters.sort : current);
        previousFilters.current = filters;
    }, [filters.search, filters.status, filters.print_type, filters.sort]);
    useEffect(() => {
        if (skipSearchDebounce.current) {
            skipSearchDebounce.current = false;
            return;
        }
        if (search.trim() === filters.search) return;

        searchTimer.current = window.setTimeout(() => applyFilters({ search: search.trim() }), 400);
        return () => window.clearTimeout(searchTimer.current);
    }, [search, filters.search, filters.status, filters.print_type, filters.sort]);

    function applyFilters(changes = {}) {
        window.clearTimeout(searchTimer.current);
        const next = { search: search.trim(), status, print_type: printType, sort, ...changes };
        if (next.print_type === 'none') next.print_type = '';
        router.get(withEmbeddedContext(indexUrl), Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value !== 'recent')), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function clearFilters() {
        skipSearchDebounce.current = true;
        setSearch('');
        setStatus('');
        setPrintType('none');
        setSort('recent');
        applyFilters({ search: '', status: '', print_type: '', sort: 'recent' });
    }

    const hasFilters = Boolean(search || status || printType !== 'none' || sort !== 'recent');

    return (
        <>
            <Head title="Configurations" />
            <div style={{ display: 'flex', justifyContent: 'center', width: '100%' }}>
                <div style={{ width: '100%', maxWidth: '1080px' }}>
                <s-page heading="Configurations" inlineSize="large">
                <s-button slot="primary-action" variant="primary" href={withEmbeddedContext(createUrl)} onClick={(event) => visitEmbedded(event, createUrl)}>
                    Create configuration
                </s-button>

                <s-section heading="Configurations" subheading="Filter the list and review matching configurations below." padding="none">
                    <s-stack direction="block" gap="none">
                        <s-box padding="base">
                            <s-stack direction="block" gap="base">
                                <s-stack direction="inline" gap="tight" alignItems="center">
                                    {[
                                        ['', 'All', statusCounts.all],
                                        ['active', 'Active', statusCounts.active],
                                        ['draft', 'Draft', statusCounts.draft],
                                    ].map(([value, label, count]) => (
                                        <s-button key={label} variant={status === value ? 'primary' : 'tertiary'} onClick={() => { setStatus(value); applyFilters({ status: value }); }}>
                                            {label} ({count})
                                        </s-button>
                                    ))}
                                    {hasFilters && <s-button variant="tertiary" onClick={clearFilters}>Clear filters</s-button>}
                                </s-stack>
                                <s-grid gridTemplateColumns="repeat(auto-fit, minmax(190px, 1fr))" gap="base">
                                    <s-text-field
                                        label="Shopify product type"
                                        placeholder="Search product types"
                                        value={search}
                                        onInput={(event) => setSearch(event.currentTarget.value)}
                                        onChange={(event) => setSearch(event.currentTarget.value)}
                                        onKeyDown={(event) => { if (event.key === 'Enter') applyFilters({ search: search.trim() }); }}
                                    />
                                    <s-select label="Print type" value={printType} onChange={(event) => { const value = event.currentTarget.value; setPrintType(value); applyFilters({ print_type: value }); }}>
                                        <s-option value="none">All print types</s-option>
                                        {printTypeOptions.map((option) => <s-option key={option.id} value={option.id}>{option.title}</s-option>)}
                                    </s-select>
                                    <s-select label="Sort by" value={sort} onChange={(event) => { const value = event.currentTarget.value; setSort(value); applyFilters({ sort: value }); }}>
                                        <s-option value="recent">Recently updated</s-option>
                                        <s-option value="oldest">Oldest first</s-option>
                                        <s-option value="product_type">Product type A–Z</s-option>
                                    </s-select>
                                </s-grid>
                            </s-stack>
                        </s-box>
                        {configurations.data.length === 0 ? (
                            <s-stack direction="block" gap="base" padding="base">
                                <s-heading>{hasFilters ? 'No matching configurations' : 'No configurations yet'}</s-heading>
                                <s-paragraph>{hasFilters ? 'Try another product type or print type, or clear the filters.' : 'Create a configuration to set up print choices for your store.'}</s-paragraph>
                                {hasFilters
                                    ? <s-button onClick={clearFilters}>Clear filters</s-button>
                                    : <s-button variant="primary" href={withEmbeddedContext(createUrl)} onClick={(event) => visitEmbedded(event, createUrl)}>Create configuration</s-button>}
                            </s-stack>
                        ) : (
                            <s-table>
                                <s-table-header-row>
                                    <s-table-header listSlot="primary">Shopify product type</s-table-header>
                                    <s-table-header listSlot="labeled">Print types</s-table-header>
                                    <s-table-header listSlot="inline">Status</s-table-header>
                                    <s-table-header listSlot="inline">Updated</s-table-header>
                                    <s-table-header listSlot="inline">Action</s-table-header>
                                </s-table-header-row>
                                <s-table-body>
                                    {configurations.data.map((configuration) => (
                                        <s-table-row key={configuration.id}>
                                            <s-table-cell>
                                                <s-stack direction="block" gap="tight">
                                                    <s-link href={withEmbeddedContext(`/configurations/${configuration.id}`)} onClick={(event) => visitEmbedded(event, `/configurations/${configuration.id}`)}>{configuration.shopify_product_type}</s-link>
                                                    <s-text tone="subdued">Configuration #{configuration.id}</s-text>
                                                </s-stack>
                                            </s-table-cell>
                                            <s-table-cell>
                                                <s-stack direction="block" gap="tight">
                                                    <s-text>{configuration.print_type_names.length ? configuration.print_type_names.slice(0, 3).join(' · ') : 'No print types'}</s-text>
                                                    {configuration.print_type_names.length > 3 && <s-text tone="subdued">+{configuration.print_type_names.length - 3} more</s-text>}
                                                </s-stack>
                                            </s-table-cell>
                                            <s-table-cell><s-badge tone={configuration.status === 'active' ? 'success' : 'info'}>{configuration.status === 'active' ? 'Active' : 'Draft'}</s-badge></s-table-cell>
                                            <s-table-cell><s-text tone="subdued">{formatUpdatedAt(configuration.updated_at)}</s-text></s-table-cell>
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
                </div>
            </div>
        </>
    );
}
