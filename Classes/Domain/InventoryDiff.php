<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * What changed between two inventories, value by value.
 *
 * Two things make the result readable rather than literal. Strings that hold
 * JSON, composer.json and composer.lock above all, are compared as the
 * structure they contain, not as one huge changed text. And lists whose
 * elements carry a name are matched by that name, so a package inserted in
 * the middle of composer.lock does not shift every package after it.
 */
final class InventoryDiff
{
    /**
     * In this order: the first one every element of a list carries is the key.
     */
    private const NAME_FIELDS = ['name', 'identifier', 'languageId'];

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<InventoryChange>
     */
    public function between(array $before, array $after): array
    {
        $changes = [];
        $this->compare($before, $after, [], $changes);

        return $changes;
    }

    /**
     * @param list<string> $path
     * @param list<InventoryChange> $changes
     */
    private function compare(mixed $before, mixed $after, array $path, array &$changes): void
    {
        $before = $this->structured($before);
        $after = $this->structured($after);

        if (!is_array($before) || !is_array($after)) {
            if ($before !== $after) {
                $changes[] = new InventoryChange($path, ChangeKind::CHANGED, $before, $after);
            }

            return;
        }

        $before = $this->byName($before);
        $after = $this->byName($after);

        foreach ($before as $key => $value) {
            if (!array_key_exists($key, $after)) {
                $changes[] = new InventoryChange([...$path, (string)$key], ChangeKind::REMOVED, $value, null);
                continue;
            }
            $this->compare($value, $after[$key], [...$path, (string)$key], $changes);
        }

        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before)) {
                $changes[] = new InventoryChange([...$path, (string)$key], ChangeKind::ADDED, null, $value);
            }
        }
    }

    /**
     * A string that holds a JSON object or array is compared as that.
     */
    private function structured(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * A list of named elements, keyed by name: "[typo3/cms-core]".
     *
     * @param array<int|string, mixed> $list
     * @return array<int|string, mixed>
     */
    private function byName(array $list): array
    {
        if ($list === [] || !array_is_list($list)) {
            return $list;
        }

        foreach (self::NAME_FIELDS as $field) {
            $named = [];
            foreach ($list as $element) {
                if (!is_array($element) || !is_scalar($element[$field] ?? null)) {
                    continue 2;
                }
                $named['[' . $element[$field] . ']'] = $element;
            }

            if (count($named) === count($list)) {
                return $named;
            }
        }

        return $list;
    }
}
