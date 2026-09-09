<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Command;

use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Evaluation\EvaluationException;
use Caretaker2\Hub\Evaluation\EvaluationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class EvaluateCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly EvaluationService $evaluation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Wertet die Composer-Daten der Instanzen aus und schreibt Befunde')
            ->addOption('instance', 'i', InputOption::VALUE_REQUIRED, 'Nur diese Instanz auswerten');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $only = $input->getOption('instance');
        $instances = $only !== null
            ? array_filter([$this->instances->findByUid((int)$only)])
            : $this->instances->findAll();

        if ($instances === []) {
            $io->warning('Keine Instanz gefunden.');

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($instances as $instance) {
            $io->section($instance->title);

            try {
                $counts = $this->evaluation->evaluate($instance);
            } catch (EvaluationException $e) {
                // One instance that cannot be evaluated must not abort the run.
                $io->error($e->getMessage());
                $failed++;
                continue;
            }

            $io->writeln(sprintf(
                '  %d neu, %d unverändert, %d erledigt',
                $counts['added'],
                $counts['kept'],
                $counts['resolved']
            ));
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
