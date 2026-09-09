<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Caretaker2\Hub\Domain\Instance;

/**
 * One kind of judgement about an instance.
 *
 * The counterpart to the agent's ProviderInterface: a provider answers what an
 * instance is, an evaluator says what is wrong with it. An extension that
 * brings its own provider can bring the evaluator that reads it.
 *
 * An evaluator sees the whole inventory, because some judgements need two
 * providers at once. One that throws is caught and reported as an unassessable
 * finding, so a single broken judgement does not cost the others.
 */
interface EvaluatorInterface
{
    /**
     * Stable, short, used in identifiers and messages.
     */
    public function key(): string;

    /**
     * @param array<string, mixed> $inventory
     * @return list<Finding>
     */
    public function evaluate(Instance $instance, array $inventory): array;
}
