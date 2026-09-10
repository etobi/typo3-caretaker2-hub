<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * Strips the values that depend on which PHP runtime collected the inventory
 * rather than on the instance itself. A scheduler push runs under CLI, a
 * hub-triggered pull under FPM, and they disagree about all of these. Left
 * in, every alternation between the two would look like a change.
 *
 * Which values those are, each provider says itself in its "volatile" list.
 * Agents from before that field get the list the hub keeps for them.
 *
 * The data still reaches the hub and is shown. It just does not decide
 * whether a snapshot is stored, and it does not show up when two snapshots
 * are compared.
 */
final class InventoryNormalizer
{
    /**
     * Per provider key, dotted paths under its data; "*" for the whole
     * provider. Mirrors what the agent's providers declare today.
     */
    private const VOLATILE_BY_DEFAULT = [
        'platform' => [
            'php.sapi',
            'php.settings',
            'php.extensions.ext-pcntl',
            'php.extensions.ext-posix',
            'php.extensions.ext-readline',
            'php.extensions.ext-cgi-fcgi',
            'php.extensions.ext-apache2handler',
            'php.extensions.ext-litespeed',
        ],
        'reports' => ['*'],
        'scheduler' => ['runs'],
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
        unset($inventory['generatedAt']);

        $providers = $inventory['providers'] ?? null;
        if (!is_array($providers)) {
            return $inventory;
        }

        foreach ($providers as $key => $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $paths = is_array($provider['volatile'] ?? null)
                ? $provider['volatile']
                : (self::VOLATILE_BY_DEFAULT[$key] ?? []);

            if (in_array('*', $paths, true)) {
                unset($providers[$key]);
                continue;
            }

            // The declaration itself is not a property of the instance.
            unset($providers[$key]['volatile']);

            foreach ($paths as $path) {
                if (is_array($providers[$key]['data'] ?? null)) {
                    $providers[$key]['data'] = $this->forget($providers[$key]['data'], explode('.', (string)$path));
                }
            }
        }

        $inventory['providers'] = $providers;

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
