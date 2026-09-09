<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\SnapshotRepository;

final class EvaluationService
{
    public function __construct(
        private readonly SnapshotRepository $snapshots,
        private readonly ComposerEvaluator $evaluator,
        private readonly FindingFactory $factory,
        private readonly FindingRepository $findings,
        private readonly InstanceRepository $instances,
    ) {}

    /**
     * @return array{added: int, kept: int, resolved: int}
     * @throws EvaluationException
     */
    public function evaluate(Instance $instance): array
    {
        $inventory = $this->snapshots->findLatestInventory($instance->uid);
        if ($inventory === null) {
            throw new EvaluationException('Für diese Instanz liegt noch kein Inventar vor.');
        }

        $result = $this->evaluator->evaluate($instance->uid, $instance->tenant, $inventory);

        $counts = $this->findings->replaceForInstance(
            $instance->uid,
            $instance->tenant,
            $this->factory->fromResult($result)
        );

        $this->instances->update($instance->uid, [
            'needs_evaluation' => 0,
            'evaluated_at' => time(),
        ]);

        return $counts;
    }
}
