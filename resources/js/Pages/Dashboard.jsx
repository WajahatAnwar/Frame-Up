import { Head } from '@inertiajs/react';
import { withEmbeddedContext } from '../shopify-auth';
import { visitEmbedded } from '../polaris-navigation';

export default function Dashboard({ stats, recentConfigurations, indexUrl, createUrl, catalogUrl }) {
    return (
        <>
            <Head title="Dashboard" />
            <s-page heading="Dashboard" inlineSize="base">
                <s-button slot="primary-action" variant="primary" href={withEmbeddedContext(createUrl)} onClick={(event) => visitEmbedded(event, createUrl)}>
                    Create configuration
                </s-button>

                <s-section heading="Your configurations">
                    <s-grid gridTemplateColumns="repeat(auto-fit, minmax(160px, 1fr))" gap="base">
                        {[
                            ['Total', stats.configurations],
                            ['Active', stats.active],
                            ['Draft', stats.draft],
                        ].map(([label, value]) => (
                            <s-box key={label} padding="base" background="base" borderWidth="base" borderColor="base" borderRadius="base">
                                <s-stack direction="block" gap="tight">
                                    <s-text tone="subdued">{label}</s-text>
                                    <s-heading>{value}</s-heading>
                                </s-stack>
                            </s-box>
                        ))}
                    </s-grid>
                </s-section>

                <s-section heading="Print catalog">
                    <s-stack direction="block" gap="base">
                        <s-paragraph>
                            {stats.printTypes > 0
                                ? `${stats.printTypes} print types and ${stats.surfaces} surfaces are ready to use in configurations.`
                                : 'The shared print catalog is empty. Pull catalog data before configuring products.'}
                        </s-paragraph>
                        <s-link href={withEmbeddedContext(catalogUrl)} onClick={(event) => visitEmbedded(event, catalogUrl)}>Manage catalog connection</s-link>
                    </s-stack>
                </s-section>

                <s-section heading="Recent configurations">
                    {recentConfigurations.length === 0 ? (
                        <s-stack direction="block" gap="base">
                            <s-paragraph>No configurations yet. Create one to set up print choices for your store.</s-paragraph>
                            <s-button variant="primary" href={withEmbeddedContext(createUrl)} onClick={(event) => visitEmbedded(event, createUrl)}>Create configuration</s-button>
                        </s-stack>
                    ) : (
                        <s-stack direction="block" gap="base">
                            <s-table>
                                <s-table-header-row>
                                    <s-table-header>Configuration</s-table-header>
                                    <s-table-header>Status</s-table-header>
                                </s-table-header-row>
                                <s-table-body>
                                    {recentConfigurations.map((configuration) => (
                                        <s-table-row key={configuration.id}>
                                            <s-table-cell>
                                                <s-stack direction="block" gap="tight">
                                                    <s-link href={withEmbeddedContext(`/configurations/${configuration.id}`)} onClick={(event) => visitEmbedded(event, `/configurations/${configuration.id}`)}>{configuration.name}</s-link>
                                                    <s-text tone="subdued">{configuration.shopify_product_type || 'No Shopify product type'}</s-text>
                                                </s-stack>
                                            </s-table-cell>
                                            <s-table-cell><s-badge tone={configuration.status === 'active' ? 'success' : 'info'}>{configuration.status === 'active' ? 'Active' : 'Draft'}</s-badge></s-table-cell>
                                        </s-table-row>
                                    ))}
                                </s-table-body>
                            </s-table>
                            <s-link href={withEmbeddedContext(indexUrl)} onClick={(event) => visitEmbedded(event, indexUrl)}>View all configurations</s-link>
                        </s-stack>
                    )}
                </s-section>
            </s-page>
        </>
    );
}
