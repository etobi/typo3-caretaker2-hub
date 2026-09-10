<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Backend;

use Caretaker2\Hub\Domain\GroupRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Narrows the instance list down and sorts it into its groups. Works on the
 * rows InstancePresenter builds.
 */
final class InstanceListFilter
{
    private const KEYS = ['state', 'typo3', 'php', 'group'];

    public function __construct(
        private readonly GroupRepository $groups,
    ) {}

    /**
     * @return array<string, string> filter key to wanted value, '' for "any"
     */
    public function fromRequest(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        $filters = [];
        foreach (self::KEYS as $key) {
            $filters[$key] = trim((string)($query['filter_' . $key] ?? ''));
        }

        return $filters;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function apply(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach ($filters as $key => $wanted) {
                if ($wanted !== '' && self::valueOf($row, $key) !== $wanted) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Only the values that actually occur. A dropdown offering PHP 8.4 when
     * nothing runs it invites a filter that can only come back empty.
     *
     * Each option carries either a label or a label key for the template to
     * translate.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array{value: string, label: ?string, labelKey: ?string}>>
     */
    public function options(array $rows): array
    {
        $groups = $this->groups->findAllIndexed();
        $options = ['state' => [], 'typo3' => [], 'php' => [], 'group' => []];

        foreach ($rows as $row) {
            $options['state'][$row['state']->value] = ['labelKey' => $row['state']->getLabelKey()];

            if ((int)$row['typo3Major'] > 0) {
                $options['typo3'][(string)$row['typo3Major']] = ['label' => 'TYPO3 ' . $row['typo3Major']];
            }

            if ($row['phpBranch'] !== '') {
                $options['php'][(string)$row['phpBranch']] = ['label' => 'PHP ' . $row['phpBranch']];
            }

            $groupUid = (int)$row['groupUid'];
            $options['group'][(string)$groupUid] = isset($groups[$groupUid])
                ? ['label' => $groups[$groupUid]['title']]
                : ['labelKey' => 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:list.group.ungrouped'];
        }

        $out = [];
        foreach ($options as $key => $values) {
            ksort($values, $key === 'state' ? SORT_STRING : SORT_NATURAL);
            $out[$key] = [];
            foreach ($values as $value => $labelling) {
                $out[$key][] = [
                    'value' => (string)$value,
                    'label' => $labelling['label'] ?? null,
                    'labelKey' => $labelling['labelKey'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * Groups in their configured order, then the rows without a group under
     * uid 0 with an empty title.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{uid: int, title: string, description: string, instances: list<array<string, mixed>>}>
     */
    public function group(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $buckets[$row['groupUid']][] = $row;
        }

        $out = [];
        foreach ($this->groups->findAllIndexed() as $uid => $group) {
            if (!isset($buckets[$uid])) {
                continue;
            }
            $out[] = [
                'uid' => $uid,
                'title' => $group['title'],
                'description' => $group['description'],
                'instances' => $buckets[$uid],
            ];
            unset($buckets[$uid]);
        }

        $remaining = array_merge([], ...array_values($buckets));
        if ($remaining !== []) {
            $out[] = ['uid' => 0, 'title' => '', 'description' => '', 'instances' => $remaining];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function valueOf(array $row, string $key): string
    {
        return match ($key) {
            'state' => $row['state']->value,
            'typo3' => (string)$row['typo3Major'],
            'php' => (string)$row['phpBranch'],
            'group' => (string)$row['groupUid'],
            default => '',
        };
    }
}
