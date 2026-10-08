<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PullCatalogFrom3dFrames implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function uniqueId(): string
    {
        return 'global-catalog';
    }

    public function handle(): void
    {
        $baseUrl = rtrim((string) config('services.frame_up_source.url'), '/');
        $key = (string) config('services.frame_up_source.key');

        if ($baseUrl === '' || $key === '') {
            throw new RuntimeException('Frame Up catalog source is not configured.');
        }

        $url = $baseUrl.'/api/frame-up/catalog';
        $requestUri = parse_url($url, PHP_URL_PATH);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$requestUri, $key);
        $data = Http::acceptJson()
            ->withHeaders(['X-ABS-Timestamp' => $timestamp, 'X-ABS-Signature' => $signature])
            ->connectTimeout(5)
            ->timeout(60)
            ->get($url)
            ->throw()
            ->json('data');

        $tables = [
            'collections', 'products', 'product_settings', 'product_varients', 'product_media',
            'collection_product', 'addon_product', 'addons_collections_sizes',
        ];

        if (! is_array($data)) {
            throw new RuntimeException('Frame Up catalog response is missing data.');
        }

        foreach ($tables as $table) {
            if (! isset($data[$table]) || ! is_array($data[$table]) || ! array_is_list($data[$table])) {
                throw new RuntimeException("Frame Up catalog response is missing {$table} rows.");
            }

            $columns = Schema::getColumnListing($table);
            foreach ($data[$table] as $index => $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    throw new RuntimeException("Frame Up catalog contains an invalid {$table} row at index {$index}: an ID is required.");
                }
                $unmappedColumns = array_diff(array_keys($row), $columns);
                if ($unmappedColumns !== []) {
                    throw new RuntimeException("Frame Up catalog {$table} row at index {$index} contains unmapped columns: ".implode(', ', $unmappedColumns).'. Run the catalog schema migrations before retrying.');
                }
            }
        }

        DB::transaction(function () use ($data, $tables): void {
            foreach ($tables as $table) {
                $this->upsertRows($table, $data[$table]);
            }
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function upsertRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = [];
        foreach ($rows as $row) {
            $columns = array_values(array_unique([...$columns, ...array_keys($row)]));
        }
        $normalized = array_map(
            fn (array $row): array => array_replace(array_fill_keys($columns, null), $row),
            $rows,
        );

        foreach (array_chunk($normalized, 200) as $chunk) {
            DB::table($table)->upsert($chunk, ['id'], array_values(array_diff($columns, ['id'])));
        }
    }
}
