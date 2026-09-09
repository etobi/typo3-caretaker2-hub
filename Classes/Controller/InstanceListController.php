<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\SnapshotRepository;
use Caretaker2\Hub\Domain\TriggerClient;
use Caretaker2\Hub\Evaluation\EvaluationException;
use Caretaker2\Hub\Evaluation\EvaluationService;
use Caretaker2\Hub\Evaluation\FindingRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Breadcrumb\BreadcrumbContext;
use TYPO3\CMS\Backend\Dto\Breadcrumb\BreadcrumbNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

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

    private const MAX_RENDERED_VALUE_BYTES = 8192;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly InstanceRepository $instances,
        private readonly EnrollmentService $enrollment,
        private readonly SnapshotRepository $snapshots,
        private readonly UriBuilder $uriBuilder,
        private readonly TriggerClient $triggerClient,
        private readonly FindingRepository $findings,
        private readonly EvaluationService $evaluation,
        private readonly PageRenderer $pageRenderer,
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

        $wanted = (int)($request->getQueryParams()['snapshot'] ?? 0);
        $historic = $wanted > 0
            ? $this->snapshots->findInventoryByUid($wanted, $instanceId)
            : null;

        // Hiding the buttons is not enough — every action describes the
        // current state and must not run from a view of an older one.
        $readOnly = $historic !== null;

        $message = null;
        $messageSeverity = 'info';

        $body = $request->getParsedBody();

        if (!$readOnly && $request->getMethod() === 'POST' && is_array($body) && isset($body['acknowledge'])) {
            $note = trim((string)($body['note'] ?? ''));

            $uids = $body['acknowledgeType'] ?? null;
            if (is_string($uids) && $uids !== '') {
                // "All 24 blocked updates" — the case where acknowledging one
                // at a time is what stops people using this at all.
                $selected = $this->findings->findOpenUidsByType($instanceId, $instance->tenant, $uids);
            } else {
                $selected = array_map('intval', (array)($body['findings'] ?? []));
            }

            $count = $this->findings->acknowledgeMany(
                $selected,
                $instanceId,
                $instance->tenant,
                $this->currentUser($request),
                $note
            );

            if ($count === 0) {
                $message = 'Nichts ausgewählt.';
                $messageSeverity = 'warning';
            } else {
                $message = $count === 1
                    ? 'Ein Befund quittiert.'
                    : sprintf('%d Befunde quittiert.', $count);
                $messageSeverity = 'success';
            }
        }

        if (!$readOnly && $request->getMethod() === 'POST' && is_array($body) && isset($body['unacknowledge'])) {
            $this->findings->unacknowledge((int)$body['unacknowledge']);
            $message = 'Quittierung aufgehoben.';
            $messageSeverity = 'info';
        }

        if (!$readOnly && $request->getMethod() === 'POST' && ($request->getParsedBody()['evaluate'] ?? null) !== null) {
            try {
                $counts = $this->evaluation->evaluate($instance);
                $message = sprintf(
                    'Auswertung fertig: %d neu, %d unverändert, %d erledigt.',
                    $counts['added'],
                    $counts['kept'],
                    $counts['resolved']
                );
                $messageSeverity = 'success';
            } catch (EvaluationException $e) {
                $message = $e->getMessage();
                $messageSeverity = 'danger';
            }
        }

        if (!$readOnly && $request->getMethod() === 'POST' && ($request->getParsedBody()['trigger'] ?? null) !== null) {
            [$ok, $message] = $this->triggerClient->trigger($instance);
            $messageSeverity = $ok ? 'success' : 'warning';

            // The agent pushes synchronously, so the fresh data is already
            // here — re-read the instance instead of showing the stale row.
            $instance = $this->instances->findByUid($instanceId) ?? $instance;
        }

        $this->pageRenderer->loadJavaScriptModule('@caretaker2/hub/acknowledge.js');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $instance->title);
        $view->getDocHeaderComponent()->setBreadcrumbContext(
            new BreadcrumbContext(null, $this->breadcrumb($instance, $historic))
        );

        $inventory = $historic !== null
            ? $historic['inventory']
            : $this->snapshots->findLatestInventory($instanceId);

        $all = $this->findings->findForInstance($instanceId);
        $open = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 0));
        $acknowledged = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 1));

        $view->assignMultiple([
            'instance' => $this->present($instance, time()),
            // Findings always describe the current state, so they are hidden
            // while an older snapshot is on screen rather than shown next to
            // data they do not belong to.
            'historic' => $historic !== null,
            'historicAt' => $historic['crdate'] ?? 0,
            'shown' => $this->inventorySummary($inventory),
            'currentUri' => (string)$this->uriBuilder->buildUriFromRoute(
                self::ROUTE,
                ['instance' => $instanceId]
            ),
            'providers' => $this->describeProviders($inventory),
            'inventorySize' => $inventory === null
                ? 0
                : strlen((string)json_encode($inventory)),
            'generatedAt' => $inventory['generatedAt'] ?? null,
            'schemaVersion' => $inventory['schemaVersion'] ?? null,
            'history' => $this->presentHistory($this->snapshots->findHistory($instanceId), $instanceId, $wanted),
            'snapshotCount' => $this->snapshots->countForInstance($instanceId),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
            'findings' => $this->presentFindings($open),
            'acknowledgedFindings' => $this->presentFindings($acknowledged),
            'findingCounts' => $this->findings->countsForInstance($instanceId),
            'openByType' => $this->openByType($open),
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
        $counts = $this->findings->countsForInstances(
            array_map(static fn(Instance $i): int => $i->uid, $instances)
        );

        $view->assignMultiple([
            'instances' => array_map(
                fn(Instance $i): array => $this->present($i, $now, $counts[$i->uid] ?? []),
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
    private function present(Instance $instance, int $now, array $findingCounts = []): array
    {
        $state = $instance->healthState($now);

        // A high or critical security finding outranks everything else. An
        // instance that is reporting cleanly but is vulnerable is not "current".
        if (($findingCounts['securityHigh'] ?? 0) > 0 && $state !== 'stale') {
            $state = 'vulnerable';
        }

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
            'findings' => $findingCounts,
        ];
    }

    /**
     * @param list<Instance> $instances
     * @return array<string, int>
     */
    private function summarize(array $instances, int $now): array
    {
        $summary = ['total' => count($instances), 'ok' => 0, 'incomplete' => 0, 'stale' => 0, 'vulnerable' => 0];
        $counts = $this->findings->countsForInstances(
            array_map(static fn(Instance $i): int => $i->uid, $instances)
        );

        foreach ($instances as $instance) {
            $state = $this->present($instance, $now, $counts[$instance->uid] ?? [])['state'];
            $summary[$state]++;
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
            [$json, $omitted] = $this->renderable($data);

            $out[] = [
                'key' => (string)$key,
                'status' => $status,
                'severity' => ['ok' => 'success', 'degraded' => 'warning'][$status] ?? 'danger',
                'reason' => is_array($entry) ? ($entry['reason'] ?? null) : null,
                'message' => is_array($entry) ? ($entry['message'] ?? null) : null,
                'json' => $json,
                'omitted' => $omitted,
            ];
        }

        return $out;
    }

    /**
     * A composer.lock is a quarter of a megabyte. Dumping it into the page
     * would make the whole view unusable, so oversized values are replaced by
     * a note naming their size — the data itself is untouched in the snapshot.
     *
     * @param mixed $data
     * @return array{0: string|null, 1: list<array{key: string, bytes: int}>}
     */
    private function renderable($data): array
    {
        if ($data === null) {
            return [null, []];
        }

        $omitted = [];

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $size = strlen((string)json_encode($value));
                if ($size > self::MAX_RENDERED_VALUE_BYTES) {
                    $omitted[] = ['key' => (string)$key, 'bytes' => $size];
                    $data[$key] = sprintf('… %s Bytes, hier nicht dargestellt', number_format($size, 0, ',', '.'));
                }
            }
        }

        return [
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $omitted,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentFindings(array $rows): array
    {
        $typeLabels = [
            'security' => 'Sicherheit',
            'update_safe' => 'Update möglich',
            'update_major' => 'Update blockiert',
            'abandoned' => 'Nicht gepflegt',
            'unassessable' => 'Nicht bewertbar',
        ];
        $severityColours = [
            'critical' => 'danger',
            'high' => 'danger',
            'unknown' => 'warning',
            'medium' => 'warning',
            'low' => 'info',
            'info' => 'secondary',
        ];

        return array_map(static function (array $row) use ($typeLabels, $severityColours): array {
            $severity = (string)$row['severity'];

            return [
                'type' => (string)$row['finding_type'],
                'typeLabel' => $typeLabels[$row['finding_type']] ?? (string)$row['finding_type'],
                'severity' => $severity,
                'severityColour' => $severityColours[$severity] ?? 'secondary',
                'package' => (string)$row['package'],
                'installedVersion' => (string)$row['installed_version'],
                'latestVersion' => (string)$row['latest_version'],
                'title' => (string)$row['title'],
                'link' => (string)$row['link'],
                'firstSeen' => (int)$row['first_seen'],
                'uid' => (int)$row['uid'],
                'ackNote' => (string)($row['ack_note'] ?? ''),
                'ackUser' => (string)$row['ack_user'],
                'ackAt' => (int)$row['ack_at'],
            ];
        }, $rows);
    }

    /**
     * @param array{crdate: int, inventory: array<string, mixed>}|null $historic
     * @return list<BreadcrumbNode>
     */
    private function breadcrumb(Instance $instance, ?array $historic): array
    {
        // The module hierarchy is prepended by TYPO3 itself, so these are the
        // nodes below it — anything else would show "Instanzen" twice.
        $nodes = [
            new BreadcrumbNode(
                identifier: 'caretaker2-instance-' . $instance->uid,
                label: $instance->title,
                icon: 'caretaker2-module',
                url: $historic === null ? null : (string)$this->uriBuilder->buildUriFromRoute(
                    self::ROUTE,
                    ['instance' => $instance->uid]
                ),
            ),
        ];

        if ($historic !== null) {
            $nodes[] = new BreadcrumbNode(
                identifier: 'caretaker2-snapshot',
                label: date('d.m.Y H:i', $historic['crdate']),
                icon: 'actions-history',
            );
        }

        return $nodes;
    }

    /**
     * The header values of whichever snapshot is on screen — not of the
     * instance row, which always holds the latest.
     *
     * @param array<string, mixed>|null $inventory
     * @return array<string, string>
     */
    private function inventorySummary(?array $inventory): array
    {
        $core = $inventory['providers']['core']['data'] ?? [];
        $platform = $inventory['providers']['platform']['data'] ?? [];

        $database = trim(sprintf(
            '%s %s',
            (string)($platform['database']['platform'] ?? ''),
            $this->shortenDbVersion((string)($platform['database']['serverVersion'] ?? ''))
        ));

        return [
            'typo3Version' => (string)($core['version'] ?? ''),
            'context' => (string)($core['applicationContext'] ?? ''),
            'phpVersion' => (string)($platform['php']['version'] ?? ''),
            'database' => $database,
        ];
    }

    /**
     * @param list<array{uid: int, crdate: int, fingerprint: string}> $history
     * @return list<array<string, mixed>>
     */
    private function presentHistory(array $history, int $instanceId, int $current): array
    {
        $latest = $history[0]['uid'] ?? 0;

        return array_map(function (array $entry) use ($instanceId, $current, $latest): array {
            return [
                'uid' => $entry['uid'],
                'crdate' => $entry['crdate'],
                'fingerprint' => $entry['fingerprint'],
                'isLatest' => $entry['uid'] === $latest,
                'isActive' => $entry['uid'] === $current || ($current === 0 && $entry['uid'] === $latest),
                'uri' => (string)$this->uriBuilder->buildUriFromRoute(
                    self::ROUTE,
                    ['instance' => $instanceId, 'snapshot' => $entry['uid']]
                ),
            ];
        }, $history);
    }

    /**
     * How many open findings there are per type, for the bulk buttons.
     *
     * @param list<array<string, mixed>> $open
     * @return list<array<string, mixed>>
     */
    private function openByType(array $open): array
    {
        $labels = [
            'security' => 'Sicherheitsbefunde',
            'update_safe' => 'mögliche Updates',
            'update_major' => 'blockierte Updates',
            'abandoned' => 'nicht gepflegte Pakete',
            'unassessable' => 'nicht bewertbare Repositories',
        ];

        $counts = [];
        foreach ($open as $row) {
            $type = (string)$row['finding_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $type => $count) {
            $out[] = [
                'type' => $type,
                'count' => $count,
                'label' => $labels[$type] ?? $type,
            ];
        }

        return $out;
    }

    /**
     * Whoever clicked. Stored alongside the note so that in half a year one
     * can ask the person who waved a finding through.
     */
    private function currentUser(ServerRequestInterface $request): string
    {
        $user = $request->getAttribute('backend.user');
        if ($user !== null && isset($user->user['username'])) {
            return (string)$user->user['username'];
        }

        return (string)($GLOBALS['BE_USER']->user['username'] ?? 'unbekannt');
    }

    private function stateLabel(string $state): string
    {
        return [
            'ok' => 'Aktuell',
            'vulnerable' => 'Sicherheitslücke',
            // Bewusst nicht "Fehler": Es ist nichts kaputt, wir wissen nur
            // nicht alles. Das ist eine eigene Aussage und darf nicht als
            // Entwarnung durchgehen.
            'incomplete' => 'Unvollständig geprüft',
            'stale' => 'Meldet sich nicht',
        ][$state] ?? $state;
    }

    private function stateSeverity(string $state): string
    {
        return [
            'ok' => 'success',
            'incomplete' => 'warning',
            'vulnerable' => 'danger',
            'stale' => 'danger',
        ][$state] ?? 'default';
    }

    /**
     * "10.11.18-MariaDB-ubu2204-log" ist als Spalteninhalt unbrauchbar.
     */
    private function shortenDbVersion(string $version): string
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }
}
