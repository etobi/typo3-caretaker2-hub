<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation\Evaluator;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\PhpVersions;
use Caretaker2\Hub\Evaluation\EvaluatorInterface;
use Caretaker2\Hub\Evaluation\Finding;

/**
 * The same argument as for the TYPO3 version, one layer down: a PHP branch
 * without security fixes tears the application open no matter how well the
 * application itself is kept.
 */
final class PhpVersionFindings implements EvaluatorInterface
{
    public function __construct(
        private readonly PhpVersions $phpVersions,
    ) {}

    public function key(): string
    {
        return 'php-version';
    }

    public function evaluate(Instance $instance, array $inventory): array
    {
        if ($instance->phpVersion === '') {
            return [];
        }

        $status = $this->phpVersions->statusOf($instance->phpVersion);
        $branch = 'PHP ' . $status['cycle'];

        if ($status['status'] === PhpVersions::STATUS_EOL) {
            return [new Finding(
                type: Finding::TYPE_PHP_EOL,
                severity: 'high',
                identifier: 'php-' . $status['cycle'],
                package: 'php',
                installedVersion: $instance->phpVersion,
                latestVersion: $status['latest'],
                title: sprintf(
                    '%s erhält keine Sicherheitsfixes mehr%s.',
                    $branch,
                    $status['eolUntil'] !== null ? ' — der Zweig endete am ' . date('d.m.Y', $status['eolUntil']) : ''
                ),
                link: 'https://www.php.net/supported-versions.php',
            )];
        }

        if ($status['status'] === PhpVersions::STATUS_SECURITY) {
            return [new Finding(
                type: Finding::TYPE_PHP_SECURITY_ONLY,
                severity: 'medium',
                identifier: 'php-' . $status['cycle'],
                package: 'php',
                installedVersion: $instance->phpVersion,
                latestVersion: $status['latest'],
                title: sprintf(
                    '%s bekommt nur noch Sicherheitsfixes%s.',
                    $branch,
                    $status['eolUntil'] !== null ? ', bis ' . date('d.m.Y', $status['eolUntil']) : ''
                ),
                link: 'https://www.php.net/supported-versions.php',
            )];
        }

        return [];
    }
}
