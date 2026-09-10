<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;
use Caretaker2\Hub\Evaluation\Severity;

/**
 * What the database server says about the tables: character sets that the
 * next major will choke on, engines without transactions, and the tables
 * TYPO3 fills but never empties.
 */
final class DatabaseFindings implements EvaluatorInterface
{
    private const LL = 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:';

    private const EXPECTED_CHARSET = 'utf8mb4';
    private const EXPECTED_ENGINE = 'innodb';

    /**
     * Character sets that are no character set of the table's text: a
     * binary column reports "binary", and TYPO3 itself declares hash
     * columns as ascii.
     */
    private const IGNORED_CHARSETS = ['', 'binary', 'ascii'];

    /**
     * Tables TYPO3 appends to and cleans up only when told to, with the
     * size from which that is worth saying.
     */
    private const HOUSEKEEPING_TABLES = ['sys_log', 'sys_history', 'sys_file_processedfile'];
    private const HOUSEKEEPING_ROWS = 1000000;
    private const HOUSEKEEPING_BYTES = 1024 * 1024 * 1024;

    private const MAX_NAMES = 8;

    public function key(): string
    {
        return 'database';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        $provider = $inventory['providers']['database'] ?? null;
        if (!is_array($provider)) {
            return [];
        }

        $status = (string)($provider['status'] ?? 'unavailable');
        $data = is_array($provider['data'] ?? null) ? $provider['data'] : [];
        $findings = [];

        if ($status !== 'ok') {
            $findings[] = new Finding(
                type: Finding::TYPE_UNASSESSABLE,
                severity: Severity::INFO,
                identifier: 'database-' . (string)($provider['reason'] ?? $status),
                package: 'database',
                installedVersion: '',
                latestVersion: '',
                title: self::LL . 'finding.title.database.incomplete',
                link: '',
                titleArguments: [(string)($provider['message'] ?? $provider['reason'] ?? $status)],
            );
        }

        if ($data === []) {
            return $findings;
        }

        $tables = array_values(array_filter($data['tables'] ?? [], 'is_array'));

        return array_merge(
            $findings,
            $this->charsetFindings($tables),
            $this->defaultCharsetFinding($data),
            $this->engineFindings($tables),
            $this->housekeepingFindings($data),
        );
    }

    /**
     * One finding per foreign character set, naming the tables. A table
     * counts under a character set when its default or any of its columns
     * uses it.
     *
     * @param list<array<string, mixed>> $tables
     * @return list<Finding>
     */
    private function charsetFindings(array $tables): array
    {
        $byCharset = [];
        foreach ($tables as $table) {
            $name = (string)($table['name'] ?? '');
            $charsets = [(string)($table['characterSet'] ?? '')];
            foreach (array_keys((array)($table['columnCharacterSets'] ?? [])) as $columnCharset) {
                $charsets[] = (string)$columnCharset;
            }

            foreach (array_unique($charsets) as $charset) {
                if ($charset === self::EXPECTED_CHARSET || in_array($charset, self::IGNORED_CHARSETS, true)) {
                    continue;
                }
                $byCharset[$charset][] = $name;
            }
        }

        ksort($byCharset);
        $findings = [];

        foreach ($byCharset as $charset => $names) {
            // utf8mb3 stores most text and fails only on emoji and the like;
            // latin1 and its kin lose whole scripts.
            $legacy = $charset !== 'utf8mb3';

            $findings[] = new Finding(
                type: Finding::TYPE_DATABASE_CHARSET,
                severity: $legacy ? Severity::MEDIUM : Severity::LOW,
                identifier: 'database-charset-' . $charset,
                package: $charset,
                installedVersion: '',
                latestVersion: self::EXPECTED_CHARSET,
                title: self::LL . ($legacy ? 'finding.title.database.charsetLegacy' : 'finding.title.database.charsetUtf8mb3'),
                link: 'https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Installation/SystemRequirements/Database.html',
                titleArguments: [(string)count($names), $charset, $this->names($names)],
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Finding>
     */
    private function defaultCharsetFinding(array $data): array
    {
        $default = strtolower((string)($data['characterSet'] ?? ''));
        if ($default === '' || $default === self::EXPECTED_CHARSET) {
            return [];
        }

        return [new Finding(
            type: Finding::TYPE_DATABASE_CHARSET,
            severity: Severity::LOW,
            identifier: 'database-default-charset',
            package: $default,
            installedVersion: '',
            latestVersion: self::EXPECTED_CHARSET,
            title: self::LL . 'finding.title.database.defaultCharset',
            link: 'https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Installation/SystemRequirements/Database.html',
            titleArguments: [$default],
        )];
    }

    /**
     * @param list<array<string, mixed>> $tables
     * @return list<Finding>
     */
    private function engineFindings(array $tables): array
    {
        $byEngine = [];
        foreach ($tables as $table) {
            $engine = (string)($table['engine'] ?? '');
            if ($engine === '' || strtolower($engine) === self::EXPECTED_ENGINE) {
                continue;
            }
            $byEngine[$engine][] = (string)($table['name'] ?? '');
        }

        ksort($byEngine);
        $findings = [];

        foreach ($byEngine as $engine => $names) {
            $findings[] = new Finding(
                type: Finding::TYPE_DATABASE_ENGINE,
                severity: Severity::LOW,
                identifier: 'database-engine-' . strtolower($engine),
                package: $engine,
                installedVersion: '',
                latestVersion: 'InnoDB',
                title: self::LL . 'finding.title.database.engine',
                link: '',
                titleArguments: [(string)count($names), $engine, $this->names($names)],
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Finding>
     */
    private function housekeepingFindings(array $data): array
    {
        $sizes = is_array($data['sizes']['tables'] ?? null) ? $data['sizes']['tables'] : [];
        $findings = [];

        foreach (self::HOUSEKEEPING_TABLES as $table) {
            $size = $sizes[$table] ?? null;
            if (!is_array($size)) {
                continue;
            }

            $rows = (int)($size['rows'] ?? 0);
            $bytes = (int)($size['dataBytes'] ?? 0) + (int)($size['indexBytes'] ?? 0);
            if ($rows < self::HOUSEKEEPING_ROWS && $bytes < self::HOUSEKEEPING_BYTES) {
                continue;
            }

            $findings[] = new Finding(
                type: Finding::TYPE_DATABASE_HOUSEKEEPING,
                severity: Severity::LOW,
                identifier: 'database-housekeeping-' . $table,
                package: $table,
                installedVersion: '',
                latestVersion: '',
                title: self::LL . 'finding.title.database.housekeeping',
                link: 'https://docs.typo3.org/c/typo3/cms-scheduler/main/en-us/Administration/CleanupTasks.html',
                titleArguments: [$table, number_format($rows, 0, '.', ' '), $this->bytes($bytes)],
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $names
     */
    private function names(array $names): string
    {
        sort($names);
        $shown = array_slice($names, 0, self::MAX_NAMES);
        $text = implode(', ', $shown);

        return count($names) > self::MAX_NAMES ? $text . ', …' : $text;
    }

    private function bytes(int $bytes): string
    {
        foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'kB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1, '.', ' ') . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
