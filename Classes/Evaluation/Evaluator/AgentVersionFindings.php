<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\HubVersion;
use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;
use Caretaker2\Hub\Evaluation\Severity;

/**
 * Whether the agent on the instance is the one that belongs to this hub.
 *
 * Hub and agent share one version number. An older agent still reports, but
 * it does not collect what the newer hub judges, and its findings would be
 * silently incomplete. That weighs as much as a missing security fix, so it
 * is a high finding and not a note.
 */
final class AgentVersionFindings implements EvaluatorInterface
{
    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly HubVersion $hubVersion,
    ) {}

    public function key(): string
    {
        return 'agent-version';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        $installed = $instance->agentVersion;
        $expected = $this->hubVersion->current();

        // Only two released versions compare. A checkout reports "dev-main",
        // an agent that could not read its own version "0.0.0", and an
        // instance that has not reported yet nothing at all. None of those
        // says the agent is old.
        if (!self::isRelease($installed) || !self::isRelease($expected)) {
            return [];
        }

        if (version_compare($installed, $expected, '>=')) {
            return [];
        }

        return [new Finding(
            type: Finding::TYPE_AGENT_OUTDATED,
            severity: Severity::HIGH,
            identifier: 'caretaker2/agent',
            package: 'caretaker2/agent',
            installedVersion: $installed,
            latestVersion: $expected,
            title: self::LL . 'finding.title.agent.outdated',
            link: '',
            titleArguments: [$installed, $expected],
        )];
    }

    private static function isRelease(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 && $version !== '0.0.0';
    }
}
