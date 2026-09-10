<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Domain;

/**
 * One value that differs between two snapshots.
 */
final readonly class InventoryChange
{
    /**
     * @param list<string> $path keys from the root down; a segment in
     *                           brackets names a list element by its name
     */
    public function __construct(
        public array $path,
        public ChangeKind $kind,
        public mixed $before,
        public mixed $after,
    ) {}
}
