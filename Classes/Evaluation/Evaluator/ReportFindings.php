<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;
use Caretaker2\Hub\Evaluation\Severity;

final class ReportFindings implements EvaluatorInterface
{
    private const SEVERITIES = [
        'error' => Severity::HIGH,
        'warning' => Severity::MEDIUM,
    ];

    private const MAX_TITLE_LENGTH = 2000;

    public function key(): string
    {
        return 'reports';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        $provider = $inventory['providers']['reports'] ?? null;
        if (!is_array($provider)) {
            return [];
        }

        $status = (string)($provider['status'] ?? 'unavailable');
        $data = is_array($provider['data'] ?? null) ? $provider['data'] : [];
        $findings = [];

        // No checks, or only some: that is a gap in what we know, and it is
        // stated rather than passed over in silence. What did get checked
        // still counts, so a degraded run keeps its issues below.
        if ($status !== 'ok') {
            $findings[] = new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: Severity::INFO,
                identifier: 'reports-' . (string)($provider['reason'] ?? $status),
                package: 'typo3/cms-reports',
                installedVersion: '',
                latestVersion: '',
                title: 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.reports.incomplete',
                link: '',
                titleArguments: [(string)($provider['message'] ?? $provider['reason'] ?? $status)],
            );
        } elseif ((int)($data['checked'] ?? 0) === 0) {
            // "ok" with nothing looked at is not an all-clear, it is an empty
            // report. Older TYPO3 versions register their checks somewhere the
            // agent may not reach, and the difference has to stay visible.
            $findings[] = new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: Severity::INFO,
                identifier: 'reports-none',
                package: 'typo3/cms-reports',
                installedVersion: '',
                latestVersion: '',
                title: 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.reports.none',
                link: '',
            );
        }

        foreach ($data['issues'] ?? [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $severity = self::SEVERITIES[(string)($issue['severity'] ?? '')] ?? null;
            if ($severity === null) {
                continue;
            }

            $group = (string)($issue['provider'] ?? '');
            $title = (string)($issue['title'] ?? '');
            $message = (string)($issue['message'] ?? '');

            $distinct = $title !== '' ? $title : $message;
            if ($distinct === '') {
                continue;
            }

            $findings[] = new Finding(
                type: Finding::TYPE_REPORT,
                severity: $severity,
                identifier: 'report-' . substr(hash('sha256', $group . "\0" . $distinct), 0, 24),
                package: $group,
                installedVersion: (string)($issue['value'] ?? ''),
                latestVersion: '',
                title: $this->sentence($title, $message),
                link: '',
            );
        }

        return $findings;
    }

    /**
     * Title and message as one text. The message carries the detail and is
     * often the only part that says what to do; when it has lines of its
     * own, they start under the title.
     */
    private function sentence(string $title, string $message): string
    {
        if ($title === '' || $message === '') {
            $text = $title . $message;
        } elseif (str_contains($message, "\n")) {
            $text = $title . ":\n" . $message;
        } else {
            $text = $title . ': ' . $message;
        }

        return mb_strlen($text) > self::MAX_TITLE_LENGTH
            ? mb_substr($text, 0, self::MAX_TITLE_LENGTH) . '…'
            : $text;
    }
}
