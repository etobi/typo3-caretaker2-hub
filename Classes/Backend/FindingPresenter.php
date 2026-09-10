<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Backend;

use Caretaker2\Hub\Evaluation\Severity;

/**
 * Finding rows as the detail view shows them.
 */
final class FindingPresenter
{
    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly Labels $labels,
    ) {}

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function present(array $rows): array
    {
        return array_map(fn(array $row): array => [
            'uid' => (int)$row['uid'],
            'type' => (string)$row['finding_type'],
            'typeLabelKey' => self::LL . 'finding.type.' . $row['finding_type'],
            'severity' => Severity::fromStored((string)$row['severity']),
            'package' => (string)$row['package'],
            'installedVersion' => (string)$row['installed_version'],
            'latestVersion' => (string)$row['latest_version'],
            'title' => $this->title((string)$row['title'], (string)($row['title_args'] ?? '')),
            'link' => (string)$row['link'],
            'firstSeen' => (int)$row['first_seen'],
            'ackNote' => (string)($row['ack_note'] ?? ''),
            'ackUser' => (string)$row['ack_user'],
            'ackAt' => (int)$row['ack_at'],
        ], $rows);
    }

    /**
     * A stored title is either one of our keys with its arguments, or foreign
     * text that stays as it came: an advisory, a message from a TYPO3 check.
     */
    private function title(string $title, string $arguments): string
    {
        if (!str_starts_with($title, 'LLL:')) {
            return $title;
        }

        $args = $arguments === '' ? [] : json_decode($arguments, true);
        $args = is_array($args) ? array_map('strval', $args) : [];

        try {
            return $this->labels->translate($title, ...$args);
        } catch (\Throwable $e) {
            return $this->labels->translate($title);
        }
    }
}
