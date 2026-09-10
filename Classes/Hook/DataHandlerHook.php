<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Hook;

use Caretaker2\Hub\Domain\CleanupService;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\GroupRepository;
use Caretaker2\Hub\Domain\TriggerSecret;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class DataHandlerHook
{
    public function __construct(
        private readonly CleanupService $cleanup,
        private readonly TriggerSecret $secret,
    ) {}

    /**
     * The trigger password is typed in clear and stored sealed. A value that
     * is sealed already is the untouched one the form sent back.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table === InstanceRepository::TABLE && isset($fieldArray['trigger_password'])) {
            $fieldArray['trigger_password'] = $this->secret->seal((string)$fieldArray['trigger_password']);
        }
    }

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
