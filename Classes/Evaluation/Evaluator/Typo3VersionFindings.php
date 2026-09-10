<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\Typo3MajorVersions;
use Caretaker2\Hub\Domain\Typo3Support;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;
use Caretaker2\Hub\Evaluation\Severity;

/**
 * The support status of the installed TYPO3 version.
 */
final class Typo3VersionFindings implements EvaluatorInterface
{
    public function __construct(
        private readonly Typo3MajorVersions $majorVersions,
    ) {}

    public function key(): string
    {
        return 'typo3-version';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        if ($instance->typo3Major <= 0) {
            return [];
        }

        $status = $this->majorVersions->statusOf(
            $instance->typo3Version !== '' ? $instance->typo3Version : (string)$instance->typo3Major
        );
        // Inside the ELTS window but on the last freely published release: the
        // instance gets nothing. That weighs more than running ELTS does.
        if ($status['status'] === Typo3Support::ELTS_UNPATCHED) {
            return [new Finding(
                type: Finding::TYPE_TYPO3_ELTS_UNPATCHED,
                severity: Severity::HIGH,
                identifier: 'typo3-' . $instance->typo3Major,
                package: 'typo3/cms-core',
                installedVersion: $instance->typo3Version,
                latestVersion: $status['latest'],
                title: $status['eltsUntil'] !== null
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.typo3.eltsUnpatched'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.typo3.eltsUnpatchedUndated',
                link: '',
                titleArguments: $status['eltsUntil'] !== null
                    ? [(string)$instance->typo3Major, $status['lastPublic'], date('d.m.Y', $status['eltsUntil'])]
                    : [(string)$instance->typo3Major, $status['lastPublic']],
            )];
        }

        if ($status['status'] === Typo3Support::ELTS) {
            return [new Finding(
                type: Finding::TYPE_TYPO3_ELTS,
                severity: Severity::MEDIUM,
                identifier: 'typo3-' . $instance->typo3Major,
                package: 'typo3/cms-core',
                installedVersion: $instance->typo3Version,
                latestVersion: '',
                title: $status['eltsUntil'] !== null
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.typo3.elts'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.typo3.eltsUndated',
                link: '',
                titleArguments: $status['eltsUntil'] !== null
                    ? [(string)$instance->typo3Major, date('d.m.Y', $status['eltsUntil'])]
                    : [(string)$instance->typo3Major],
            )];
        }

        if ($status['status'] === Typo3Support::UNSUPPORTED) {
            return [new Finding(
                type: Finding::TYPE_TYPO3_UNSUPPORTED,
                severity: Severity::HIGH,
                identifier: 'typo3-' . $instance->typo3Major,
                package: 'typo3/cms-core',
                installedVersion: $instance->typo3Version,
                latestVersion: '',
                title: $status['eltsUntil'] !== null
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.typo3.unsupported'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.typo3.unsupportedUndated',
                link: 'https://typo3.org/cms/roadmap',
                titleArguments: $status['eltsUntil'] !== null
                    ? [(string)$instance->typo3Major, date('d.m.Y', $status['eltsUntil'])]
                    : [(string)$instance->typo3Major],
            )];
        }

        return [];
    }
}
