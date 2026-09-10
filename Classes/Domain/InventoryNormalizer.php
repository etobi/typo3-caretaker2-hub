<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * Strips the values that depend on which PHP runtime collected the inventory
 * rather than on the instance itself. A scheduler push runs under CLI, a
 * hub-triggered pull under FPM, and they disagree about all of these. Left
 * in, every alternation between the two would look like a change.
 *
 * The data still reaches the hub and is shown. It just does not decide
 * whether a snapshot is stored, and it does not show up when two snapshots
 * are compared.
 */
final class InventoryNormalizer
{
    private const VOLATILE_PATHS = [
        ['generatedAt'],
        ['providers', 'platform', 'data', 'php', 'sapi'],
        ['providers', 'platform', 'data', 'php', 'settings'],
        // TYPO3's own checks judge the runtime they happen to run in, so a
        // scheduler push and a hub-triggered pull disagree about two of them.
        // They describe the current state, not a change to the installation,
        // and are always available in full from last_inventory.
        ['providers', 'reports'],
    ];

    private const SAPI_BOUND_EXTENSIONS = [
        'ext-pcntl',
        'ext-posix',
        'ext-readline',
        'ext-cgi-fcgi',
        'ext-litespeed',
    ];

    /**
     * @param array<string, mixed> $inventory
     */
    public function fingerprint(array $inventory): string
    {
        return hash('sha256', (string)json_encode($this->normalize($inventory)));
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    public function normalize(array $inventory): array
    {
        foreach (self::VOLATILE_PATHS as $path) {
            $inventory = $this->forget($inventory, $path);
        }

        $extensions = &$inventory['providers']['platform']['data']['php']['extensions'];
        if (is_array($extensions)) {
            foreach (self::SAPI_BOUND_EXTENSIONS as $name) {
                unset($extensions[$name]);
            }
        }
        unset($extensions);

        return $inventory;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private function forget(array $data, array $path): array
    {
        $key = array_shift($path);

        if (!array_key_exists($key, $data)) {
            return $data;
        }

        if ($path === []) {
            unset($data[$key]);

            return $data;
        }

        if (is_array($data[$key])) {
            $data[$key] = $this->forget($data[$key], $path);
        }

        return $data;
    }
}
