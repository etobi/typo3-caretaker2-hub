<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Command;

use Caretaker2\Hub\Domain\EnrollmentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class CreateEnrollmentCodeCommand extends Command
{
    public function __construct(private readonly EnrollmentService $enrollment)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Erzeugt einen Enrollment-Code zum Verbinden einer Instanz (15 Minuten gültig)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Code: ' . $this->enrollment->createCode());
        $io->writeln('Im Backend-Modul "Caretaker2" der Instanz eintragen. Gültig für 15 Minuten.');

        return Command::SUCCESS;
    }
}
