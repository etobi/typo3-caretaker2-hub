<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Hook;

use Caretaker2\Hub\Domain\CleanupService;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\GroupRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class DataHandlerHook
{
    public function __construct(
        private readonly CleanupService $cleanup,
    ) {}

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
        if ($table === InstanceRepository::TABLE) {
            $this->cleanup->forgetInstance((int)$uid);
        }

        if ($table === GroupRepository::TABLE) {
            $this->cleanup->detachGroup((int)$uid);
        }
    }
}
