<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

/**
 * One thing that is wrong with an instance.
 */
final readonly class Finding
{
    public const TYPE_SECURITY = 'security';
    public const TYPE_UPDATE_SAFE = 'update_safe';
    public const TYPE_UPDATE_MAJOR = 'update_major';
    public const TYPE_ABANDONED = 'abandoned';
    public const TYPE_UNASSESSABLE = 'unassessable';
    public const TYPE_TYPO3_ELTS = 'typo3_elts';
    public const TYPE_TYPO3_ELTS_UNPATCHED = 'typo3_elts_unpatched';
    public const TYPE_TYPO3_UNSUPPORTED = 'typo3_unsupported';
    public const TYPE_PHP_SECURITY_ONLY = 'php_security_only';
    public const TYPE_PHP_EOL = 'php_eol';
    public const TYPE_REPORT = 'report';
    public const TYPE_DOMAIN_CONTESTED = 'domain_contested';

    public function __construct(
        public string $type,
        public Severity $severity,
        public string $identifier,
        public string $package,
        public string $installedVersion,
        public string $latestVersion,
        public string $title,
        public string $link,
        /** @var list<string|array{date: int}> */
        public array $titleArguments = [],
    ) {}

    /**
     * A title argument that is a date. Stored as the timestamp and formatted
     * only when shown, so the display follows the backend's date format.
     *
     * @return array{date: int}
     */
    public static function date(int $timestamp): array
    {
        return ['date' => $timestamp];
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'finding_type' => $this->type,
            'severity' => $this->severity->value,
            'identifier' => $this->identifier,
            'package' => $this->package,
            'installed_version' => $this->installedVersion,
            'latest_version' => $this->latestVersion,
            'title' => $this->title,
            'title_args' => $this->titleArguments === [] ? '' : json_encode($this->titleArguments),
            'link' => $this->link,
        ];
    }
}
