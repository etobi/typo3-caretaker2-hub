<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Backend;

use Caretaker2\Hub\Domain\InventoryChange;

/**
 * Changes between two snapshots, grouped by provider and with values that
 * fit on a page.
 */
final class DiffPresenter
{
    private const MAX_VALUE_LENGTH = 1000;

    /**
     * @param list<InventoryChange> $changes
     * @return list<array{provider: string, changes: list<array<string, mixed>>}>
     */
    public function present(array $changes): array
    {
        $groups = [];
        foreach ($changes as $change) {
            [$provider, $path] = $this->splitProvider($change->path);
            $groups[$provider][] = [
                'path' => $this->pathString($path),
                'kind' => $change->kind,
                'before' => $this->format($change->before),
                'after' => $this->format($change->after),
            ];
        }

        ksort($groups, SORT_STRING);

        $out = [];
        foreach ($groups as $provider => $rows) {
            $out[] = ['provider' => (string)$provider, 'changes' => $rows];
        }

        return $out;
    }

    /**
     * "providers.composer.data.lock" belongs to the provider "composer"; a
     * path outside the providers gets the empty group.
     *
     * @param list<string> $path
     * @return array{0: string, 1: list<string>}
     */
    private function splitProvider(array $path): array
    {
        if (($path[0] ?? null) === 'providers' && isset($path[1])) {
            return [$path[1], array_slice($path, 2)];
        }

        return ['', $path];
    }

    /**
     * @param list<string> $segments
     */
    private function pathString(array $segments): string
    {
        $out = '';
        foreach ($segments as $segment) {
            $out .= $out !== '' && !str_starts_with($segment, '[') ? '.' . $segment : $segment;
        }

        return $out;
    }

    private function format(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string)$value,
        };

        return mb_strlen($text) > self::MAX_VALUE_LENGTH
            ? mb_substr($text, 0, self::MAX_VALUE_LENGTH) . ' …'
            : $text;
    }
}
