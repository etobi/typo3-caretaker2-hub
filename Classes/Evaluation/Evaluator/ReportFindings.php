<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;

/**
 * What TYPO3 already found out about itself.
 *
 * The agent hands over the reports framework's own statuses — install tool
 * password, devIPmask, file permissions, whatever extensions add. They arrive
 * pre-filtered: only what is above OK. Turning them into findings costs almost
 * nothing and doubles what the hub can say about an instance.
 *
 * The wording stays as TYPO3 wrote it. Rephrasing would mean maintaining a
 * translation of every check in every extension, and the original is what an
 * administrator will search for.
 */
final class ReportFindings implements EvaluatorInterface
{
    private const SEVERITIES = [
        'error' => 'high',
        'warning' => 'medium',
    ];

    private const MAX_TITLE_LENGTH = 400;

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

        // No checks, or only some: that is a gap in what we know, and it is
        // stated rather than passed over in silence.
        if ($status !== 'ok') {
            return [new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: 'info',
                identifier: 'reports-' . $status,
                package: 'typo3/cms-reports',
                installedVersion: '',
                latestVersion: '',
                title: sprintf(
                    'TYPO3s eigene Prüfungen liegen nur unvollständig vor: %s',
                    (string)($provider['message'] ?? $provider['reason'] ?? $status)
                ),
                link: '',
            )];
        }

        $findings = [];
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

            $findings[] = new Finding(
                type: Finding::TYPE_REPORT,
                severity: $severity,
                // The check itself has no id, so one is derived from where it
                // came from and what it is called. Both are stable as long as
                // the check is; a renamed check counts as a new one, which is
                // the honest reading anyway.
                identifier: 'report-' . substr(hash('sha256', $group . "\0" . $title), 0, 24),
                package: $group,
                installedVersion: (string)($issue['value'] ?? ''),
                latestVersion: '',
                title: $this->sentence($title, (string)($issue['message'] ?? '')),
                link: '',
            );
        }

        return $findings;
    }

    /**
     * Title and message as one line. The message carries the detail and is
     * often the only part that says what to do.
     */
    private function sentence(string $title, string $message): string
    {
        $text = $message === '' ? $title : $title . ': ' . $message;

        return mb_strlen($text) > self::MAX_TITLE_LENGTH
            ? mb_substr($text, 0, self::MAX_TITLE_LENGTH) . '…'
            : $text;
    }
}
