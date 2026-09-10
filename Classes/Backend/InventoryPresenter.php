<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Backend;

/**
 * The parts of an inventory the detail view shows: the providers as the
 * agent delivered them, the sites, and a few headline values.
 */
final class InventoryPresenter
{
    private const MAX_RENDERED_VALUE_BYTES = 8192;

    public function __construct(
        private readonly Labels $labels,
    ) {}

    /**
     * @param array<string, mixed>|null $inventory
     * @return array<string, string>
     */
    public function summary(?array $inventory): array
    {
        $core = $inventory['providers']['core']['data'] ?? [];
        $platform = $inventory['providers']['platform']['data'] ?? [];

        $database = trim(sprintf(
            '%s %s',
            (string)($platform['database']['platform'] ?? ''),
            $this->shortenDbVersion((string)($platform['database']['serverVersion'] ?? ''))
        ));

        return [
            'typo3Version' => (string)($core['version'] ?? ''),
            'context' => (string)($core['applicationContext'] ?? ''),
            'phpVersion' => (string)($platform['php']['version'] ?? ''),
            'database' => $database,
        ];
    }

    /**
     * @param array<string, mixed>|null $inventory
     */
    public function size(?array $inventory): int
    {
        return $inventory === null ? 0 : strlen((string)json_encode($inventory));
    }

    /**
     * @param array<string, mixed>|null $inventory
     */
    public function generatedAt(?array $inventory): int
    {
        $value = $inventory['generatedAt'] ?? null;
        if (!is_string($value) || $value === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Every provider, including those that delivered nothing.
     *
     * @param array<string, mixed>|null $inventory
     * @return list<array<string, mixed>>
     */
    public function providers(?array $inventory): array
    {
        $providers = $inventory['providers'] ?? null;
        if (!is_array($providers)) {
            return [];
        }

        $out = [];
        foreach ($providers as $key => $entry) {
            $status = is_array($entry) ? (string)($entry['status'] ?? 'unavailable') : 'unavailable';
            $data = is_array($entry) ? ($entry['data'] ?? null) : null;
            [$json, $omitted] = $this->renderable($data);

            $out[] = [
                'key' => (string)$key,
                'status' => $status,
                'severity' => ['ok' => 'success', 'degraded' => 'warning'][$status] ?? 'danger',
                'reason' => is_array($entry) ? ($entry['reason'] ?? null) : null,
                'message' => is_array($entry) ? ($entry['message'] ?? null) : null,
                'json' => $json,
                'omitted' => $omitted,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $inventory
     * @return list<array<string, mixed>>
     */
    public function sites(?array $inventory): array
    {
        $sites = $inventory['providers']['sites']['data']['sites'] ?? null;
        if (!is_array($sites)) {
            return [];
        }

        $out = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }

            $title = trim((string)($site['websiteTitle'] ?? ''));

            $out[] = [
                'identifier' => (string)($site['identifier'] ?? ''),
                'title' => $title !== '' ? $title : (string)($site['identifier'] ?? ''),
                'rootPageId' => (int)($site['rootPageId'] ?? 0),
                'hosts' => array_values(array_filter((array)($site['hosts'] ?? []), 'is_string')),
            ];
        }

        return $out;
    }

    /**
     * The data as JSON, with values too large for a page replaced by a note
     * that says how large they were.
     *
     * @return array{0: string|null, 1: list<array{key: string, bytes: int}>}
     */
    private function renderable(mixed $data): array
    {
        if ($data === null) {
            return [null, []];
        }

        $omitted = [];

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $size = strlen((string)json_encode($value));
                if ($size > self::MAX_RENDERED_VALUE_BYTES) {
                    $omitted[] = ['key' => (string)$key, 'bytes' => $size];
                    $data[$key] = $this->labels->get(
                        'detail.providers.omittedValue',
                        number_format($size)
                    );
                }
            }
        }

        return [
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $omitted,
        ];
    }

    private function shortenDbVersion(string $version): string
    {
        // "10.11.18-MariaDB-ubu2204-log" is unusable as a headline value.
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }
}
