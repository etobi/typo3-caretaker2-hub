<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

final readonly class EvaluationResult
{
    /**
     * @param array<string, list<array<string, mixed>>> $advisories
     * @param array<string, string|null> $abandoned
     * @param list<array<string, mixed>> $packages
     * @param list<string> $unresolvableRepositories
     * @param array<string, string> $platform
     * @param array<string, string> $lockedVersions installed versions by package name, from the lock file
     * @param string|null $updateCheckError why "composer outdated" contributed nothing, if it broke off
     */
    public function __construct(
        public array $advisories,
        public array $abandoned,
        public array $packages,
        public array $unresolvableRepositories,
        public array $platform,
        public array $lockedVersions = [],
        public ?string $updateCheckError = null,
    ) {}
}
