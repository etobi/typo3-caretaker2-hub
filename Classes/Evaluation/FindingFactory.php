<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

/**
 * Turns composer's output into findings.
 *
 * The one judgement made here is the severity mapping. Everything else is a
 * direct translation — composer already knows whether an update is allowed by
 * the constraint, and that distinction is what makes the list actionable
 * rather than noisy.
 */
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
                    // Composer leaves severity empty for some advisories.
                    // Unknown is not the same as harmless, so it ranks above
                    // low rather than below it.
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
                    ? 'Update im Rahmen des Constraints möglich'
                    : 'Neuere Version vorhanden, der Constraint müsste geändert werden',
                // Deliberately no link. The package homepage has nothing to do
                // with what this sentence says, and a link that does not lead
                // where its text promises is worse than none.
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
                    ? sprintf('Nicht mehr gepflegt, Nachfolger: %s', $replacement)
                    : 'Nicht mehr gepflegt, kein Nachfolger benannt',
                link: '',
            );
        }

        return $findings;
    }

    /**
     * Repositories the hub cannot reach are reported as their own finding.
     * Silently leaving them out would make an instance look fully checked
     * when part of it was never looked at.
     *
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
                title: sprintf('Repository "%s" ist vom Hub aus nicht erreichbar und wurde nicht bewertet.', $url),
                link: '',
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
