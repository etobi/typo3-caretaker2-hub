<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Caretaker2\Hub\Domain\Instance;

/**
 * One kind of judgement about an instance.
 *
 * The counterpart to the agent's ProviderInterface: a provider answers what an
 * instance is, an evaluator says what is wrong with it. Splitting them the same
 * way keeps the two halves symmetrical — an extension that brings its own
 * provider can bring the evaluator that reads it, and neither side has to be
 * touched here.
 *
 * An evaluator sees the whole inventory, not just its own provider's part:
 * some judgements need two of them (a PHP branch against a TYPO3 version, say).
 * It reports what it finds and stays quiet about what it cannot judge — an
 * evaluator that throws is caught and reported as an unassessable finding, so
 * one broken judgement does not cost all the others.
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
