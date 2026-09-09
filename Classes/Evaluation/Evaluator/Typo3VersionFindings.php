<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\Typo3MajorVersions;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;

/**
 * The support status of the installed TYPO3 version.
 *
 * Unlike the composer findings this one does not depend on the instance but on
 * the calendar: an untouched installation becomes vulnerable purely because a
 * date passes. Which is why evaluation runs by age as well as on change.
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
        $version = 'TYPO3 ' . $instance->typo3Major;

        // Im ELTS-Zeitraum, aber auf dem letzten frei veroeffentlichten Stand:
        // die Instanz bekommt nichts. Das wiegt schwerer als ELTS zu fahren.
        if ($status['status'] === Typo3MajorVersions::STATUS_ELTS_UNPATCHED) {
            return [new Finding(
                type: Finding::TYPE_TYPO3_ELTS_UNPATCHED,
                severity: 'high',
                identifier: 'typo3-' . $instance->typo3Major,
                package: 'typo3/cms-core',
                installedVersion: $instance->typo3Version,
                latestVersion: $status['latest'],
                title: sprintf(
                    '%s wird regulär nicht mehr gepflegt, und %s ist das letzte frei veröffentlichte Release. Sicherheitsupdates gibt es seither nur über ELTS%s — diese Instanz erhält keine.',
                    $version,
                    $status['lastPublic'],
                    $status['eltsUntil'] !== null ? ', noch bis ' . date('d.m.Y', $status['eltsUntil']) : ''
                ),
                link: '',
            )];
        }

        if ($status['status'] === Typo3MajorVersions::STATUS_ELTS) {
            return [new Finding(
                type: Finding::TYPE_TYPO3_ELTS,
                severity: 'medium',
                identifier: 'typo3-' . $instance->typo3Major,
                package: 'typo3/cms-core',
                installedVersion: $instance->typo3Version,
                latestVersion: '',
                title: sprintf(
                    '%s wird regulär nicht mehr gepflegt. Sicherheitsupdates gibt es nur noch über ELTS%s.',
                    $version,
                    $status['eltsUntil'] !== null ? ', bis ' . date('d.m.Y', $status['eltsUntil']) : ''
                ),
                link: '',
            )];
        }

        if ($status['status'] === Typo3MajorVersions::STATUS_UNSUPPORTED) {
            return [new Finding(
                type: Finding::TYPE_TYPO3_UNSUPPORTED,
                severity: 'high',
                identifier: 'typo3-' . $instance->typo3Major,
                package: 'typo3/cms-core',
                installedVersion: $instance->typo3Version,
                latestVersion: '',
                title: sprintf(
                    '%s erhält keine Sicherheitsupdates mehr%s.',
                    $version,
                    $status['eltsUntil'] !== null ? ' — auch ELTS endete am ' . date('d.m.Y', $status['eltsUntil']) : ''
                ),
                link: 'https://typo3.org/cms/roadmap',
            )];
        }

        return [];
    }
}
