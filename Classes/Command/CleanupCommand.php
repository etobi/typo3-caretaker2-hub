<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Command;

use Caretaker2\Hub\Domain\CleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Safety net for what the hook cannot see: rows removed straight from the
 * database, or a table that was rebuilt.
 */
final class CleanupCommand extends Command
{
    public function __construct(private readonly CleanupService $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Removes snapshots and findings without an instance and detaches references to deleted groups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $removed = $this->cleanup->removeOrphans();

        if (array_sum($removed) === 0) {
            $io->writeln('Nothing to clean up.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d snapshots and %d findings without an instance removed, %d instances detached from deleted groups.',
            $removed['snapshots'],
            $removed['findings'],
            $removed['groups']
        ));

        return Command::SUCCESS;
    }
}
