<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Command;

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
    private const DEFAULT_MAX_AGE_HOURS = 24;

    private const DEFAULT_LIMIT = 25;

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
            ->addOption('instance', 'i', InputOption::VALUE_REQUIRED, 'Nur diese Instanz auswerten')
            // Voreinstellung ist die Warteschlange, nicht der Rundumschlag: Der
            // Befehl läuft im Scheduler alle fünf Minuten, und v14 speichert
            // für einen Konsolen-Task keine Optionen mit — was voreingestellt
            // ist, ist damit auch das, was tatsächlich läuft.
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Alle Instanzen auswerten, unabhängig von Änderung und Alter'
            )
            ->addOption(
                'max-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Ab welchem Alter in Stunden eine Auswertung als überfällig gilt',
                (string)self::DEFAULT_MAX_AGE_HOURS
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Höchstens so viele Instanzen in einem Lauf',
                (string)self::DEFAULT_LIMIT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $only = $input->getOption('instance');

        if ($only !== null) {
            $instances = array_values(array_filter([$this->instances->findByUid((int)$only)]));
        } elseif ($input->getOption('all')) {
            $instances = $this->instances->findAll();
        } else {
            $instances = $this->instances->findPendingEvaluation(
                max(1, (int)$input->getOption('max-age')) * 3600,
                max(1, (int)$input->getOption('limit'))
            );
        }

        if ($instances === []) {
            // Nothing pending is the normal case for a frequent run, so it is
            // not a warning.
            $io->writeln('Nichts auszuwerten.');

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
