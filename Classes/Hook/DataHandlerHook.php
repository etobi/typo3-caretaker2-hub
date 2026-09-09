<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Hook;

use Caretaker2\Hub\Domain\CleanupService;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\GroupRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Removes what a deleted record leaves behind.
 *
 * Snapshots and findings carry no TCA and are therefore invisible to
 * DataHandler; deleting an instance would leave them in place. That is not
 * only waste: auto_increment reuses ids after a table is rebuilt, so a later
 * instance inherits a history that is not its own — which is exactly how the
 * third test instance ended up showing snapshots from hours before it existed.
 *
 * A hook rather than an event: the core offers no PSR-14 event for deletion,
 * and this one works unchanged from v11 to v14.
 */
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
