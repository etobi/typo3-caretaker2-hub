<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\SnapshotRepository;
use Caretaker2\Hub\Domain\TriggerClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
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
    private const ROUTE = 'caretaker2_instances';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly InstanceRepository $instances,
        private readonly EnrollmentService $enrollment,
        private readonly SnapshotRepository $snapshots,
        private readonly UriBuilder $uriBuilder,
        private readonly TriggerClient $triggerClient,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $instanceId = (int)($request->getQueryParams()['instance'] ?? 0);
        if ($instanceId > 0) {
            return $this->detail($request, $instanceId);
        }

        return $this->list($request);
    }

    /**
     * Das vollständige Inventar einer Instanz, so wie der Agent es geliefert
     * hat — inklusive der Provider, die nichts liefern konnten.
     */
    private function detail(ServerRequestInterface $request, int $instanceId): ResponseInterface
    {
        $instance = $this->instances->findByUid($instanceId);
        if ($instance === null) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
        }

        $message = null;
        $messageSeverity = 'info';

        if ($request->getMethod() === 'POST' && ($request->getParsedBody()['trigger'] ?? null) !== null) {
            [$ok, $message] = $this->triggerClient->trigger($instance);
            $messageSeverity = $ok ? 'success' : 'warning';

            // The agent pushes synchronously, so the fresh data is already
            // here — re-read the instance instead of showing the stale row.
            $instance = $this->instances->findByUid($instanceId) ?? $instance;
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $instance->title);

        $inventory = $this->snapshots->findLatestInventory($instanceId);

        $view->assignMultiple([
            'instance' => $this->present($instance, time()),
            'listUri' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
            'providers' => $this->describeProviders($inventory),
            'inventoryJson' => $inventory === null
                ? null
                : json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'generatedAt' => $inventory['generatedAt'] ?? null,
            'schemaVersion' => $inventory['schemaVersion'] ?? null,
            'history' => $this->snapshots->findHistory($instanceId),
            'snapshotCount' => $this->snapshots->countForInstance($instanceId),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
        ]);

        return $view->renderResponse('InstanceList/Detail');
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', 'Instanzen');

        $enrollmentCode = null;
        if ($request->getMethod() === 'POST' && ($request->getParsedBody()['createCode'] ?? null) !== null) {
            $enrollmentCode = $this->enrollment->createCode();
        }

        $view->assign('hubUrl', $this->publicHubUrl($request));

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
     * The address the administrator is currently reaching the hub on. That is
     * what has to be typed into the agent, so it is also the only value we can
     * be sure about without configuration.
     */
    private function publicHubUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $url = $uri->getScheme() . '://' . $uri->getHost();

        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $url .= ':' . $uri->getPort();
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Instance $instance, int $now): array
    {
        $state = $instance->healthState($now);

        return [
            'uid' => $instance->uid,
            'detailUri' => (string)$this->uriBuilder->buildUriFromRoute(
                self::ROUTE,
                ['instance' => $instance->uid]
            ),
            'title' => $instance->title,
            'url' => $instance->instanceUrl,
            'typo3Version' => $instance->typo3Version,
            'typo3Major' => $instance->typo3Major,
            'phpVersion' => $instance->phpVersion,
            'database' => trim($instance->dbPlatform . ' ' . $this->shortenDbVersion($instance->dbVersion)),
            'context' => $instance->applicationContext,
            'siteHosts' => $instance->siteHosts,
            'siteCount' => $instance->siteCount,
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

    /**
     * Der Providerstatus mit Grund und Klartext. Das ist der Punkt, an dem
     * "keine Daten" sichtbar von "keine Befunde" unterscheidbar wird — hier
     * steht, warum etwas fehlt.
     *
     * @param array<string, mixed>|null $inventory
     * @return list<array<string, mixed>>
     */
    private function describeProviders(?array $inventory): array
    {
        $providers = $inventory['providers'] ?? null;
        if (!is_array($providers)) {
            return [];
        }

        $out = [];
        foreach ($providers as $key => $entry) {
            $status = is_array($entry) ? (string)($entry['status'] ?? 'unavailable') : 'unavailable';
            $data = is_array($entry) ? ($entry['data'] ?? null) : null;

            $out[] = [
                'key' => (string)$key,
                'status' => $status,
                'severity' => ['ok' => 'success', 'degraded' => 'warning'][$status] ?? 'danger',
                'reason' => is_array($entry) ? ($entry['reason'] ?? null) : null,
                'message' => is_array($entry) ? ($entry['message'] ?? null) : null,
                'json' => $data === null
                    ? null
                    : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return $out;
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
