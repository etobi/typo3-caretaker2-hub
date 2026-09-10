<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;
use Caretaker2\Hub\Evaluation\Severity;

/**
 * A domain that more than one instance claims for itself.
 *
 * An instance decides on its own which domains it serves, so a compromised
 * one can name the domain of another. The record of the other instance is
 * untouched, but "which instance serves kunde.example.com" would have two
 * answers. Every instance involved gets the finding; a legitimate case, a
 * staging copy for instance, is acknowledged with a note.
 */
final class DomainFindings implements EvaluatorInterface
{
    public function __construct(
        private readonly InstanceRepository $instances,
    ) {}

    public function key(): string
    {
        return 'domains';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        if ($instance->siteHosts === []) {
            return [];
        }

        $findings = [];
        foreach ($instance->siteHosts as $host) {
            $others = $this->instances->findClaiming([$host], $instance->uid, $instance->tenant);
            if ($others === []) {
                continue;
            }

            $findings[] = new Finding(
                type: Finding::TYPE_DOMAIN_CONTESTED,
                severity: Severity::HIGH,
                identifier: 'domain-' . $host,
                package: $host,
                installedVersion: '',
                latestVersion: '',
                title: 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.domainContested',
                link: '',
                titleArguments: [implode(', ', array_map(static fn(Instance $o): string => $o->title, $others))],
            );
        }

        return $findings;
    }
}
