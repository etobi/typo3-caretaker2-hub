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
        if ($status['status'] === PhpVersions::STATUS_EOL) {
            return [new Finding(
                type: Finding::TYPE_PHP_EOL,
                severity: 'high',
                identifier: 'php-' . $status['cycle'],
                package: 'php',
                installedVersion: $instance->phpVersion,
                latestVersion: $status['latest'],
                title: $status['eolUntil'] !== null
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.php.eol'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.php.eolUndated',
                link: 'https://www.php.net/supported-versions.php',
                titleArguments: $status['eolUntil'] !== null
                    ? [$status['cycle'], date('d.m.Y', $status['eolUntil'])]
                    : [$status['cycle']],
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
                title: $status['eolUntil'] !== null
                    ? 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.php.securityOnly'
                    : 'LLL:EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf:finding.title.php.securityOnlyUndated',
                link: 'https://www.php.net/supported-versions.php',
                titleArguments: $status['eolUntil'] !== null
                    ? [$status['cycle'], date('d.m.Y', $status['eolUntil'])]
                    : [$status['cycle']],
            )];
        }

        return [];
    }
}
