<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

/**
 * Die Übersicht: alle Instanzen, ihr Zustand und der Knopf zum Verbinden
 * einer neuen.
 */
// Modul-Routen werden über den Container aufgelöst. Ohne diesen Tag ist der
// Controller kein öffentlicher Service, und TYPO3 fällt auf makeInstance()
// ohne Konstruktor-Argumente zurück.
#[AsController]
final class InstanceListController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly InstanceRepository $instances,
        private readonly EnrollmentService $enrollment,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', 'Instanzen');

        $enrollmentCode = null;
        if ($request->getMethod() === 'POST' && ($request->getParsedBody()['createCode'] ?? null) !== null) {
            $enrollmentCode = $this->enrollment->createCode();
        }

        $now = time();
        $instances = $this->instances->findAll();

        $view->assignMultiple([
            'instances' => array_map(
                fn(Instance $i): array => $this->present($i, $now),
                $instances
            ),
            'summary' => $this->summarize($instances, $now),
            'enrollmentCode' => $enrollmentCode,
        ]);

        return $view->renderResponse('InstanceList/Index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Instance $instance, int $now): array
    {
        $state = $instance->healthState($now);

        return [
            'uid' => $instance->uid,
            'title' => $instance->title,
            'url' => $instance->instanceUrl,
            'typo3Version' => $instance->typo3Version,
            'typo3Major' => $instance->typo3Major,
            'phpVersion' => $instance->phpVersion,
            'database' => trim($instance->dbPlatform . ' ' . $this->shortenDbVersion($instance->dbVersion)),
            'context' => $instance->applicationContext,
            'agentVersion' => $instance->agentVersion,
            'lastSeen' => $instance->lastSeen,
            'state' => $state,
            'stateLabel' => $this->stateLabel($state),
            'stateSeverity' => $this->stateSeverity($state),
        ];
    }

    /**
     * @param list<Instance> $instances
     * @return array<string, int>
     */
    private function summarize(array $instances, int $now): array
    {
        $summary = ['total' => count($instances), 'ok' => 0, 'incomplete' => 0, 'stale' => 0];

        foreach ($instances as $instance) {
            $summary[$instance->healthState($now)]++;
        }

        return $summary;
    }

    private function stateLabel(string $state): string
    {
        return [
            'ok' => 'Aktuell',
            // Bewusst nicht "Fehler": Es ist nichts kaputt, wir wissen nur
            // nicht alles. Das ist eine eigene Aussage und darf nicht als
            // Entwarnung durchgehen.
            'incomplete' => 'Unvollständig geprüft',
            'stale' => 'Meldet sich nicht',
        ][$state] ?? $state;
    }

    private function stateSeverity(string $state): string
    {
        return ['ok' => 'success', 'incomplete' => 'warning', 'stale' => 'danger'][$state] ?? 'default';
    }

    /**
     * "10.11.18-MariaDB-ubu2204-log" ist als Spalteninhalt unbrauchbar.
     */
    private function shortenDbVersion(string $version): string
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }
}
