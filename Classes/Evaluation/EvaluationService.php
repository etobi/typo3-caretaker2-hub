<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Runs every evaluator over an instance and stores what they found.
 */
final class EvaluationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param iterable<EvaluatorInterface> $evaluators
     */
    public function __construct(
        private readonly iterable $evaluators,
        private readonly FindingRepository $findings,
        private readonly InstanceRepository $instances,
    ) {}

    /**
     * @return array{added: int, kept: int, resolved: int}
     * @throws EvaluationException
     */
    public function evaluate(Instance $instance): array
    {
        $inventory = $instance->lastInventory;
        if ($inventory === null) {
            throw new EvaluationException('No inventory has arrived for this instance yet.');
        }

        $findings = [];
        foreach ($this->evaluators as $evaluator) {
            foreach ($this->runOne($evaluator, $instance, $inventory) as $finding) {
                $findings[] = $finding;
            }
        }

        $counts = $this->findings->replaceForInstance($instance->uid, $instance->tenant, $findings);

        $this->instances->update($instance->uid, [
            'needs_evaluation' => 0,
            'evaluated_at' => time(),
        ]);

        return $counts;
    }

    /**
     * @param array<string, mixed> $inventory
     * @return list<Finding>
     */
    private function runOne(EvaluatorInterface $evaluator, Instance $instance, array $inventory): array
    {
        try {
            return $evaluator->evaluate($instance, $inventory);
        } catch (\Throwable $e) {
            $this->logger?->warning('Evaluator failed', [
                'evaluator' => $evaluator->key(),
                'instance' => $instance->uid,
                'exception' => $e,
            ]);

            return [new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: Severity::INFO,
                identifier: 'evaluator-' . $evaluator->key(),
                package: '',
                installedVersion: '',
                latestVersion: '',
                title: 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.evaluatorFailed',
                link: '',
                titleArguments: [$evaluator->key(), $e->getMessage()],
            )];
        }
    }
}
