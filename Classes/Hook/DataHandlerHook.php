<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Hook;

use Caretaker2\Hub\Domain\CleanupService;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\GroupRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DataHandlerHook
{
    /**
     * @param array<string, mixed> $recordToDelete
     */
    public function processCmdmap_deleteAction(
        string $table,
        int|string $uid,
        array $recordToDelete,
        bool &$recordWasDeleted,
        DataHandler $dataHandler
    ): void {
        $cleanup = GeneralUtility::makeInstance(CleanupService::class);

        if ($table === InstanceRepository::TABLE) {
            $cleanup->forgetInstance((int)$uid);
        }

        if ($table === GroupRepository::TABLE) {
            $cleanup->detachGroup((int)$uid);
        }
    }
}
