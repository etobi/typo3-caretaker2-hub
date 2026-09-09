<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

final class FindingFactory
{
    /**
     * @return list<Finding>
     */
    public function fromResult(EvaluationResult $result): array
    {
        return array_merge(
            $this->security($result),
            $this->updates($result),
            $this->abandoned($result),
            $this->unassessable($result),
        );
    }

    /**
     * @return list<Finding>
     */
    private function security(EvaluationResult $result): array
    {
        $installed = $this->installedVersions($result);
        $findings = [];

        foreach ($result->advisories as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $identifier = (string)($advisory['advisoryId'] ?? $advisory['cve'] ?? '');
                if ($identifier === '') {
                    continue;
                }

                $findings[] = new Finding(
                    type: Finding::TYPE_SECURITY,
                    severity: $this->normalizeSeverity($advisory['severity'] ?? null),
                    identifier: $identifier,
                    package: (string)$package,
                    installedVersion: $installed[$package] ?? '',
                    latestVersion: '',
                    title: trim((string)($advisory['title'] ?? '')),
                    link: (string)($advisory['link'] ?? ''),
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function updates(EvaluationResult $result): array
    {
        $findings = [];

        foreach ($result->packages as $package) {
            $status = (string)($package['latest-status'] ?? '');
            $name = (string)($package['name'] ?? '');
            if ($name === '' || $status === 'up-to-date') {
                continue;
            }

            $latest = (string)($package['latest'] ?? '');
            if ($latest === '' || $latest === '[none matched]') {
                continue;
            }

            $isSafe = $status === 'semver-safe-update';

            $findings[] = new Finding(
                type: $isSafe ? Finding::TYPE_UPDATE_SAFE : Finding::TYPE_UPDATE_MAJOR,
                severity: $isSafe ? 'medium' : 'low',
                identifier: $name,
                package: $name,
                installedVersion: (string)($package['version'] ?? ''),
                latestVersion: $latest,
                title: $isSafe
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.update_safe'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.update_major',
                link: '',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function abandoned(EvaluationResult $result): array
    {
        $installed = $this->installedVersions($result);
        $findings = [];

        foreach ($result->abandoned as $package => $replacement) {
            $findings[] = new Finding(
                type: Finding::TYPE_ABANDONED,
                severity: 'low',
                identifier: (string)$package,
                package: (string)$package,
                installedVersion: $installed[$package] ?? '',
                latestVersion: '',
                title: is_string($replacement) && $replacement !== ''
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.abandoned'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.abandonedNoSuccessor',
                link: '',
                titleArguments: is_string($replacement) && $replacement !== '' ? [$replacement] : [],
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function unassessable(EvaluationResult $result): array
    {
        $findings = [];

        foreach ($result->unresolvableRepositories as $url) {
            $findings[] = new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: 'info',
                identifier: $url,
                package: '',
                installedVersion: '',
                latestVersion: '',
                title: 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.repositoryUnreachable',
                link: '',
                titleArguments: [$url],
            );
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function installedVersions(EvaluationResult $result): array
    {
        $versions = [];
        foreach ($result->packages as $package) {
            if (is_string($package['name'] ?? null)) {
                $versions[$package['name']] = (string)($package['version'] ?? '');
            }
        }

        return $versions;
    }

    /**
     * @param mixed $severity
     */
    private function normalizeSeverity($severity): string
    {
        $known = ['critical', 'high', 'medium', 'low'];
        $value = is_string($severity) ? strtolower($severity) : '';

        return in_array($value, $known, true) ? $value : 'unknown';
    }
}
