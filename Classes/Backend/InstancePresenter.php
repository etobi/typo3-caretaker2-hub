<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Backend;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceState;
use Caretaker2\Hub\Domain\PhpSupport;
use Caretaker2\Hub\Domain\PhpVersions;
use Caretaker2\Hub\Domain\Typo3MajorVersions;
use Caretaker2\Hub\Domain\Typo3Support;
use Caretaker2\Hub\Evaluation\Severity;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * One row of the instance list: the instance, its finding counts and the
 * support status of TYPO3 and PHP, folded into the state the list shows.
 *
 * The row carries no translated text. Labels are keys, hints are a key with
 * its arguments, and the template does the translating.
 */
final class InstancePresenter
{
    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly Typo3MajorVersions $majorVersions,
        private readonly PhpVersions $phpVersions,
    ) {}

    /**
     * @param array<string, mixed> $findingCounts as FindingRepository::countsForInstances() returns them
     * @return array<string, mixed>
     */
    public function present(Instance $instance, int $now, array $findingCounts): array
    {
        $state = $this->stateOf($instance, $now, $findingCounts);
        $php = $this->phpSupport($instance);
        $worst = $this->worstSeverity($findingCounts);

        return [
            'uid' => $instance->uid,
            'groupUid' => $instance->groupUid,
            'title' => $instance->title,
            'url' => $instance->instanceUrl,
            'typo3Version' => $instance->typo3Version,
            'typo3Major' => $instance->typo3Major,
            'phpVersion' => $instance->phpVersion,
            'phpBranch' => $php['cycle'],
            'context' => $instance->applicationContext,
            'siteHosts' => $instance->siteHosts !== []
                ? $instance->siteHosts
                : array_values(array_filter([parse_url($instance->instanceUrl, PHP_URL_HOST)])),
            'siteCount' => $instance->siteCount,
            'agentVersion' => $instance->agentVersion,
            'lastSeen' => $instance->lastSeen,
            'state' => $state,
            // "Findings open" takes the colour of the worst finding it stands for.
            'stateColour' => $state === InstanceState::ATTENTION ? $worst->getColour() : $state->getColour(),
            'stateHintKey' => $state->getHintKey(),
            'stateHintArguments' => $this->stateHintArguments($state, $instance, $findingCounts, $php['cycle']),
            'worstSeverity' => $worst,
            'typo3Support' => $this->typo3Support($instance),
            'phpSupport' => $php,
            'findings' => $findingCounts,
            'findingsBySeverity' => $this->severityBadges($findingCounts['severities'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $findingCounts
     */
    public function stateOf(Instance $instance, int $now, array $findingCounts): InstanceState
    {
        $state = $instance->healthState($now);
        if ($state === InstanceState::STALE) {
            return $state;
        }

        // A serious security finding outranks everything else. An instance
        // that is reporting cleanly but is vulnerable is not "current".
        if (($findingCounts['securityHigh'] ?? 0) > 0) {
            return InstanceState::VULNERABLE;
        }

        // No known hole, but nothing to close one with either. That is its
        // own statement and must not pass as "current".
        if ((($findingCounts['typo3Unsupported'] ?? 0) + ($findingCounts['phpUnsupported'] ?? 0)) > 0) {
            return InstanceState::UNSUPPORTED;
        }

        // "OK" is a statement, and it is only true when there is nothing at
        // all. An instance with open findings is not urgent, but it is not
        // done with either.
        if ($state === InstanceState::OK && ($findingCounts['total'] ?? 0) > 0) {
            return InstanceState::ATTENTION;
        }

        return $state;
    }

    /**
     * Badges for the finding counts, most severe first.
     *
     * @param array<string, int> $severities
     * @return list<array{severity: Severity, count: int}>
     */
    public function severityBadges(array $severities): array
    {
        $badges = [];
        foreach (Severity::cases() as $severity) {
            $count = (int)($severities[$severity->value] ?? 0);
            if ($count > 0) {
                $badges[] = ['severity' => $severity, 'count' => $count];
            }
        }

        return $badges;
    }

    /**
     * @param array<string, mixed> $findingCounts
     * @return list<string>
     */
    private function stateHintArguments(
        InstanceState $state,
        Instance $instance,
        array $findingCounts,
        string $phpCycle
    ): array {
        if ($state !== InstanceState::UNSUPPORTED) {
            return [];
        }

        $affected = [];
        if (($findingCounts['typo3Unsupported'] ?? 0) > 0) {
            $affected[] = 'TYPO3 ' . $instance->typo3Major;
        }
        if (($findingCounts['phpUnsupported'] ?? 0) > 0) {
            $affected[] = 'PHP ' . $phpCycle;
        }

        return [implode(', ', $affected)];
    }

    /**
     * @return array{status: Typo3Support, hintKey: ?string, hintArguments: list<string>}
     */
    private function typo3Support(Instance $instance): array
    {
        $status = $this->majorVersions->statusOf(
            $instance->typo3Version !== '' ? $instance->typo3Version : (string)$instance->typo3Major
        );

        [$hintKey, $hintArguments] = match (true) {
            $status['status'] === Typo3Support::ELTS_UNPATCHED
                => ['typo3.hint.eltsUnpatched', [$status['lastPublic']]],
            $status['status'] === Typo3Support::ELTS && $status['eltsUntil'] !== null
                => ['typo3.hint.elts', [BackendUtility::date($status['eltsUntil'])]],
            in_array($status['status'], [Typo3Support::STABLE, Typo3Support::OLDSTABLE], true)
                && $status['maintainedUntil'] !== null
                => ['typo3.hint.maintained', [BackendUtility::date($status['maintainedUntil'])]],
            $status['status'] === Typo3Support::UNSUPPORTED && $status['eltsUntil'] !== null
                => ['typo3.hint.ended', [BackendUtility::date($status['eltsUntil'])]],
            default => [null, []],
        };

        return [
            'status' => $status['status'],
            'hintKey' => $hintKey === null ? null : self::LL . $hintKey,
            'hintArguments' => $hintArguments,
        ];
    }

    /**
     * @return array{status: PhpSupport, cycle: string, hintKey: ?string, hintArguments: list<string>}
     */
    private function phpSupport(Instance $instance): array
    {
        $status = $this->phpVersions->statusOf($instance->phpVersion);

        [$hintKey, $until] = match ($status['status']) {
            PhpSupport::ACTIVE => ['php.hint.active', $status['supportUntil']],
            PhpSupport::SECURITY => ['php.hint.security', $status['eolUntil']],
            PhpSupport::EOL => ['php.hint.eol', $status['eolUntil']],
            PhpSupport::UNKNOWN => [null, null],
        };

        return [
            'status' => $status['status'],
            'cycle' => $status['cycle'],
            'hintKey' => $until === null ? null : self::LL . $hintKey,
            'hintArguments' => $until === null ? [] : [BackendUtility::date($until)],
        ];
    }

    /**
     * @param array<string, mixed> $findingCounts
     */
    private function worstSeverity(array $findingCounts): Severity
    {
        foreach (Severity::cases() as $severity) {
            if (($findingCounts['severities'][$severity->value] ?? 0) > 0) {
                return $severity;
            }
        }

        return Severity::INFO;
    }
}
