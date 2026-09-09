<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\ComposerEvaluator;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\FindingFactory;

/**
 * Security advisories and available updates, from composer itself.
 *
 * The expensive one: it writes the lock file to a temporary directory and lets
 * composer audit and composer outdated loose on it, both of which go to the
 * network. Which is why evaluation does not run while an agent waits for its
 * push to be answered.
 */
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
