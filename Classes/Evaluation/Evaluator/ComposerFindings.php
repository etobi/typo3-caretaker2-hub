<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\ComposerEvaluator;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\FindingFactory;

final class ComposerFindings implements EvaluatorInterface
{
    public function __construct(
        private readonly ComposerEvaluator $evaluator,
        private readonly FindingFactory $factory,
    ) {}

    public function key(): string
    {
        return 'composer';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        return $this->factory->fromResult(
            $this->evaluator->evaluate($instance->uid, $instance->tenant, $inventory)
        );
    }
}
