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
     */
    public function __construct(
        public array $advisories,
        public array $abandoned,
        public array $packages,
        public array $unresolvableRepositories,
        public array $platform,
    ) {}
}
