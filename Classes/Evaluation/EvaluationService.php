<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\Typo3MajorVersions;

final class EvaluationService
{
    public function __construct(
        private readonly ComposerEvaluator $evaluator,
        private readonly FindingFactory $factory,
        private readonly FindingRepository $findings,
        private readonly InstanceRepository $instances,
        private readonly Typo3MajorVersions $majorVersions,
    ) {}

    /**
     * @return array{added: int, kept: int, resolved: int}
     * @throws EvaluationException
     */
    public function evaluate(Instance $instance): array
    {
        $inventory = $instance->lastInventory;
        if ($inventory === null) {
            throw new EvaluationException('Für diese Instanz liegt noch kein Inventar vor.');
        }

        $result = $this->evaluator->evaluate($instance->uid, $instance->tenant, $inventory);

        $counts = $this->findings->replaceForInstance(
            $instance->uid,
            $instance->tenant,
            array_merge(
                $this->factory->fromResult($result),
                $this->versionFindings($instance)
            )
        );

        $this->instances->update($instance->uid, [
            'needs_evaluation' => 0,
            'evaluated_at' => time(),
        ]);

        return $counts;
    }

    /**
     * Der Support-Status der TYPO3-Fassung. Anders als die Composer-Befunde
     * hängt er nicht an der Instanz, sondern am Kalender: Eine unveränderte
     * Installation wird allein dadurch verwundbar, dass ein Datum verstreicht.
     *
     * @return list<Finding>
     */
    private function versionFindings(Instance $instance): array
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
                link: 'https://typo3.com/elts',
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
                link: 'https://typo3.org/cms/roadmap',
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
