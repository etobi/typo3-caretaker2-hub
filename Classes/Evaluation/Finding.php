<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

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

    public function __construct(
        public string $type,
        public string $severity,
        public string $identifier,
        public string $package,
        public string $installedVersion,
        public string $latestVersion,
        public string $title,
        public string $link,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'finding_type' => $this->type,
            'severity' => $this->severity,
            'identifier' => $this->identifier,
            'package' => $this->package,
            'installed_version' => $this->installedVersion,
            'latest_version' => $this->latestVersion,
            'title' => $this->title,
            'link' => $this->link,
        ];
    }
}
