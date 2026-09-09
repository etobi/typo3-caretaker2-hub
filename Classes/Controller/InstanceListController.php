<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\GroupRepository;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\PhpVersions;
use Caretaker2\Hub\Domain\SnapshotRepository;
use Caretaker2\Hub\Domain\TriggerClient;
use Caretaker2\Hub\Domain\Typo3MajorVersions;
use Caretaker2\Hub\Evaluation\FindingRepository;
use Caretaker2\Hub\Scheduler\HubTaskInstaller;
use Caretaker2\Hub\Scheduler\SchedulerTaskException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Breadcrumb\BreadcrumbContext;
use TYPO3\CMS\Backend\Dto\Breadcrumb\BreadcrumbNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Imaging\IconSize;
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
    private const LANGUAGE_FILE = 'EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf';

    private const LL = 'LLL:' . self::LANGUAGE_FILE . ':';
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
        private readonly PageRenderer $pageRenderer,
        private readonly GroupRepository $groups,
        private readonly Typo3MajorVersions $majorVersions,
        private readonly PhpVersions $phpVersions,
        private readonly ComponentFactory $components,
        private readonly IconFactory $icons,
        private readonly HubTaskInstaller $tasks,
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

            $count = $this->findings->acknowledgeMany(
                array_map('intval', (array)($body['findings'] ?? [])),
                $instanceId,
                $instance->tenant,
                $this->currentUser($request),
                $note
            );

            if ($count === 0) {
                $message = $this->ll('message.nothingSelected');
                $messageSeverity = 'warning';
            } else {
                $message = $count === 1
                    ? $this->ll('message.acknowledged.one')
                    : $this->ll('message.acknowledged.many', $count);
                $messageSeverity = 'success';
            }
        }

        if (!$readOnly && $request->getMethod() === 'POST' && is_array($body) && isset($body['unacknowledge'])) {
            $this->findings->unacknowledge((int)$body['unacknowledge']);
            $message = $this->ll('message.unacknowledged');
            $messageSeverity = 'info';
        }

        if (!$readOnly && $request->getMethod() === 'POST' && ($request->getParsedBody()['trigger'] ?? null) !== null) {
            [$ok, $message] = $this->triggerClient->trigger($instance);
            $messageSeverity = $ok ? 'success' : 'warning';

            // The agent pushes synchronously, so the fresh data is already
            // here — re-read the instance instead of showing the stale row.
            $instance = $this->instances->findByUid($instanceId) ?? $instance;
        }

        // The modals are built in JavaScript, so their labels have to travel
        // with the page rather than through the Fluid template.
        $this->pageRenderer->addInlineLanguageLabelFile(self::LANGUAGE_FILE);
        $this->pageRenderer->loadJavaScriptModule('@caretaker2/hub/acknowledge.js');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $instance->title);

        if (!$readOnly) {
            $this->addSubmitButton($view, 'caretaker2-actions', 'trigger', $this->ll('detail.button.refresh'), 'actions-refresh');
        }

        $view->getDocHeaderComponent()->setBreadcrumbContext(
            new BreadcrumbContext(null, $this->breadcrumb($instance, $historic))
        );

        // Der aktuelle Zustand kommt aus last_inventory, nicht aus dem letzten
        // Snapshot: Snapshots entstehen nur bei Änderung und wären für alles,
        // was von der Laufzeit abhängt, veraltet.
        $inventory = $historic !== null
            ? $historic['inventory']
            : $instance->lastInventory;

        $all = $this->findings->findForInstance($instanceId);
        $open = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 0));
        $acknowledged = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 1));

        $view->assignMultiple([
            // Mit den Befundzahlen, sonst zeigte die Detailansicht "OK", wo
            // die Liste "ohne Sicherheitsupdates" sagt.
            'instance' => $this->present(
                $instance,
                time(),
                $this->findings->countsForInstances([$instanceId])[$instanceId] ?? []
            ),
            // Findings always describe the current state, so they are hidden
            // while an older snapshot is on screen rather than shown next to
            // data they do not belong to.
            'historic' => $historic !== null,
            'historicAt' => $historic['crdate'] ?? 0,
            'shown' => $this->inventorySummary($inventory),
            'sites' => $this->sitesFrom($inventory),
            'currentUri' => (string)$this->uriBuilder->buildUriFromRoute(
                self::ROUTE,
                ['instance' => $instanceId]
            ),
            'providers' => $this->describeProviders($inventory),
            'inventorySize' => $inventory === null
                ? 0
                : strlen((string)json_encode($inventory)),
            // Comes in as an ISO 8601 string in UTC. Passed on as a timestamp
            // so it renders through the same path — and in the same time zone —
            // as every other date on the page.
            'generatedAt' => $this->toTimestamp($inventory['generatedAt'] ?? null),
            'schemaVersion' => $inventory['schemaVersion'] ?? null,
            'history' => $this->presentHistory($this->snapshots->findHistory($instanceId), $instanceId, $wanted),
            'snapshotCount' => $this->snapshots->countForInstance($instanceId),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
            // Nothing here is evaluated on the spot — the scheduler does that.
            // Saying so beats letting someone read stale findings as current.
            'evaluationPending' => $instance->needsEvaluation || $instance->evaluatedAt === 0,
            'evaluatedAt' => $instance->evaluatedAt,
            'findings' => $this->presentFindings($open),
            'acknowledgedFindings' => $this->presentFindings($acknowledged),
            'findingCounts' => $this->findings->countsForInstance($instanceId),
            'hasUnrated' => array_filter($open, static fn(array $r): bool => $r['severity'] === 'unknown') !== [],
        ]);

        return $view->renderResponse('InstanceList/Detail');
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $this->ll('list.heading'));

        $enrollmentCode = null;
        if ($request->getMethod() === 'POST' && ($request->getParsedBody()['createCode'] ?? null) !== null) {
            $enrollmentCode = $this->enrollment->createCode();
        }

        $message = null;
        $messageSeverity = 'info';

        if ($request->getMethod() === 'POST' && ($request->getParsedBody()['installTasks'] ?? null) !== null) {
            try {
                $created = $this->tasks->installMissing();
                $message = $created === 1
                    ? $this->ll('scheduler.created', $created)
                    : $this->ll('scheduler.createdMany', $created);
                $messageSeverity = 'success';
            } catch (SchedulerTaskException $e) {
                $message = $e->getMessage();
                $messageSeverity = 'danger';
            }
        }

        $view->assign('hubUrl', $this->publicHubUrl($request));

        $enrollUri = (string)$this->uriBuilder->buildUriFromRoute('ajax_caretaker2_enrollment_code');
        $this->pageRenderer->addInlineLanguageLabelFile(self::LANGUAGE_FILE);
        $this->pageRenderer->loadJavaScriptModule('@caretaker2/hub/enroll.js');

        $view->addButtonToButtonBar(
            $this->components->createGenericButton()
                ->setTag('button')
                ->setLabel($this->ll('list.button.addInstance'))
                ->setTitle($this->ll('list.button.addInstance'))
                ->setShowLabelText(true)
                ->setIcon($this->icons->getIcon('actions-plus', IconSize::SMALL))
                ->setClasses('btn btn-default')
                ->setAttributes([
                    'type' => 'submit',
                    // Der Knopf steht im DocHeader, das Formular im Inhalt.
                    // Ohne JavaScript trägt so weiterhin der serverseitige Weg.
                    'form' => 'caretaker2-enroll',
                    'name' => 'createCode',
                    'value' => '1',
                    'data-caretaker2-enroll' => $enrollUri,
                ])
        );

        $view->addButtonToButtonBar(
            $this->components->createLinkButton()
                ->setHref($this->editUri('tx_caretaker2_group', 0, true))
                ->setTitle($this->ll('list.button.addGroup'))
                ->setShowLabelText(true)
                ->setIcon($this->icons->getIcon('actions-plus', IconSize::SMALL))
        );

        $now = time();
        $instances = $this->instances->findAll();
        $counts = $this->findings->countsForInstances(
            array_map(static fn(Instance $i): int => $i->uid, $instances)
        );

        $presented = array_map(
            fn(Instance $i): array => $this->present($i, $now, $counts[$i->uid] ?? []),
            $instances
        );
        $groups = $this->groupInstances($presented);

        $view->assignMultiple([
            'groups' => $groups,
            // Eine einzige Sammelgruppe ist keine Gruppierung: dann bleibt die
            // Zwischenzeile weg.
            'showGroupHeadings' => count($groups) > 1 || ($groups[0]['uid'] ?? 0) !== 0,
            'summary' => $this->summarize($instances, $now),
            'enrollmentCode' => $enrollmentCode,
            'schedulerAvailable' => $this->tasks->isAvailable(),
            'missingTasks' => implode(', ', $this->tasks->missing()),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
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
        if ($state !== 'stale') {
            if (($findingCounts['securityHigh'] ?? 0) > 0) {
                $state = 'vulnerable';
            } elseif ((($findingCounts['typo3Unsupported'] ?? 0) + ($findingCounts['phpUnsupported'] ?? 0)) > 0) {
                // No known hole, but nothing to close one with either. That is
                // its own statement and must not pass as "current".
                $state = 'unsupported';
            }
        }

        return [
            'uid' => $instance->uid,
            'groupUid' => $instance->groupUid,
            'editUri' => $this->editUri('tx_caretaker2_instance', $instance->uid),
            'detailUri' => (string)$this->uriBuilder->buildUriFromRoute(
                self::ROUTE,
                ['instance' => $instance->uid]
            ),
            'title' => $instance->title,
            'url' => $instance->instanceUrl,
            'typo3Version' => $instance->typo3Version,
            'typo3Major' => $instance->typo3Major,
            'typo3Support' => $this->supportBadge($instance),
            'phpVersion' => $instance->phpVersion,
            'phpSupport' => $this->phpBadge($instance),
            'context' => $instance->applicationContext,
            // Ohne Site-Konfiguration bleibt nur die Instanz-Adresse. Die ist
            // eine vollständige URL, die Site-Domains sind Hostnamen — nebeneinander
            // sähe das eine mit und das andere ohne Schema aus.
            'siteHosts' => $instance->siteHosts !== []
                ? $instance->siteHosts
                : array_values(array_filter([parse_url($instance->instanceUrl, PHP_URL_HOST)])),
            'siteCount' => $instance->siteCount,
            'agentVersion' => $instance->agentVersion,
            'lastSeen' => $instance->lastSeen,
            'state' => $state,
            'stateLabel' => $this->stateLabel($state),
            'stateHint' => $this->stateHint($state, $instance, $findingCounts),
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
        $summary = ['total' => count($instances), 'ok' => 0, 'incomplete' => 0, 'stale' => 0, 'vulnerable' => 0, 'unsupported' => 0];
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
        $types = [
            'security', 'update_safe', 'update_major', 'abandoned', 'unassessable',
            'typo3_elts', 'typo3_elts_unpatched', 'php_security_only', 'php_eol',
            'typo3_unsupported', 'report',
        ];
        $typeLabels = [];
        foreach ($types as $type) {
            $typeLabels[$type] = $this->ll('finding.type.' . $type);
        }

        $severityLabels = [];
        foreach (['critical', 'high', 'unknown', 'medium', 'low', 'info'] as $severity) {
            $severityLabels[$severity] = $this->ll('finding.severity.' . $severity);
        }
        $unknownHint = $this->ll('finding.severity.unknownHint');
        $severityColours = [
            'critical' => 'danger',
            'high' => 'danger',
            'unknown' => 'danger',
            'medium' => 'warning',
            'low' => 'info',
            'info' => 'secondary',
        ];

        return array_map(function (array $row) use ($typeLabels, $severityColours, $severityLabels, $unknownHint): array {
            $severity = (string)$row['severity'];

            return [
                'type' => (string)$row['finding_type'],
                'typeLabel' => $typeLabels[$row['finding_type']] ?? (string)$row['finding_type'],
                'severity' => $severityLabels[$severity] ?? $severity,
                'severityColour' => $severityColours[$severity] ?? 'secondary',
                'severityHint' => $severity === 'unknown' ? $unknownHint : '',
                'package' => (string)$row['package'],
                'installedVersion' => (string)$row['installed_version'],
                'latestVersion' => (string)$row['latest_version'],
                'title' => $this->findingTitle((string)$row['title'], (string)($row['title_args'] ?? '')),
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
     * @param mixed $value
     */
    private function toTimestamp($value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return 0;
        }
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
     * Instances arranged under their group, ungrouped ones last. A group with
     * no instances is left out — the list answers "what do I have", not "what
     * have I defined".
     *
     * @param list<array<string, mixed>> $instances
     * @return list<array<string, mixed>>
     */
    private function groupInstances(array $instances): array
    {
        $definitions = $this->groups->findAllIndexed();
        $buckets = [];

        foreach ($instances as $instance) {
            $buckets[$instance['groupUid']][] = $instance;
        }

        $out = [];
        foreach ($definitions as $uid => $group) {
            if (!isset($buckets[$uid])) {
                continue;
            }
            $out[] = [
                'uid' => $uid,
                'title' => $group['title'],
                'description' => $group['description'],
                'editUri' => $this->editUri('tx_caretaker2_group', $uid),
                'instances' => $buckets[$uid],
            ];
            unset($buckets[$uid]);
        }

        // Also catches instances whose group was deleted: they must not vanish
        // from the list just because the record they pointed at is gone.
        $remaining = [];
        foreach ($buckets as $rest) {
            $remaining = array_merge($remaining, $rest);
        }

        if ($remaining !== []) {
            $out[] = [
                'uid' => 0,
                'title' => $this->ll('list.group.ungrouped'),
                'description' => '',
                'editUri' => '',
                'instances' => $remaining,
            ];
        }

        return $out;
    }

    /**
     * Ein Absender im DocHeader, dessen Formular im Inhalt steht. Der Weg über
     * das form-Attribut hält den serverseitigen Pfad am Leben, den es ohne
     * JavaScript weiterhin braucht.
     */
    private function addSubmitButton(
        ModuleTemplate $view,
        string $formId,
        string $name,
        string $label,
        string $icon
    ): void {
        $view->addButtonToButtonBar(
            $this->components->createGenericButton()
                ->setTag('button')
                ->setLabel($label)
                ->setTitle($label)
                ->setShowLabelText(true)
                ->setIcon($this->icons->getIcon($icon, IconSize::SMALL))
                ->setClasses('btn btn-default')
                ->setAttributes([
                    'type' => 'submit',
                    'form' => $formId,
                    'name' => $name,
                    'value' => '1',
                ])
        );
    }

    private function editUri(string $table, int $uid, bool $isNew = false): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$isNew ? 0 : $uid => $isNew ? 'new' : 'edit']],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
        ]);
    }

    /**
     * The sites of whichever snapshot is on screen, each with the domains it
     * serves. Taken from the inventory rather than from the denormalised
     * column on the instance, which only holds the flat set of hosts.
     *
     * @param array<string, mixed>|null $inventory
     * @return list<array<string, mixed>>
     */
    private function sitesFrom(?array $inventory): array
    {
        $sites = $inventory['providers']['sites']['data']['sites'] ?? null;
        if (!is_array($sites)) {
            return [];
        }

        $out = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }

            $title = trim((string)($site['websiteTitle'] ?? ''));

            $out[] = [
                'identifier' => (string)($site['identifier'] ?? ''),
                'title' => $title !== '' ? $title : (string)($site['identifier'] ?? ''),
                'rootPageId' => (int)($site['rootPageId'] ?? 0),
                'hosts' => array_values(array_filter((array)($site['hosts'] ?? []), 'is_string')),
            ];
        }

        return $out;
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
     * Beschriftung und Farbe für den Support-Status einer TYPO3-Fassung.
     * Grün für die aktuelle, blau für die noch regulär gepflegten, gelb für
     * ELTS, rot für alles ohne Unterstützung.
     *
     * @return array<string, string>
     */
    private function supportBadge(Instance $instance): array
    {
        $status = $this->majorVersions->statusOf(
            $instance->typo3Version !== '' ? $instance->typo3Version : (string)$instance->typo3Major
        );

        $labels = [
            Typo3MajorVersions::STATUS_STABLE => $this->ll('typo3.status.stable'),
            Typo3MajorVersions::STATUS_OLDSTABLE => $this->ll('typo3.status.oldstable'),
            Typo3MajorVersions::STATUS_ELTS => $this->ll('typo3.status.elts'),
            Typo3MajorVersions::STATUS_ELTS_UNPATCHED => $this->ll('typo3.status.eltsUnpatched'),
            Typo3MajorVersions::STATUS_UNSUPPORTED => $this->ll('typo3.status.unsupported'),
        ];
        $colours = [
            Typo3MajorVersions::STATUS_STABLE => 'success',
            Typo3MajorVersions::STATUS_OLDSTABLE => 'info',
            Typo3MajorVersions::STATUS_ELTS => 'warning',
            Typo3MajorVersions::STATUS_ELTS_UNPATCHED => 'danger',
            Typo3MajorVersions::STATUS_UNSUPPORTED => 'danger',
        ];

        $hint = '';
        if ($status['status'] === Typo3MajorVersions::STATUS_ELTS_UNPATCHED) {
            $hint = $this->ll('typo3.hint.eltsUnpatched', $status['lastPublic']);
        } elseif ($status['status'] === Typo3MajorVersions::STATUS_ELTS && $status['eltsUntil'] !== null) {
            $hint = $this->ll('typo3.hint.elts', date('d.m.Y', $status['eltsUntil']));
        } elseif ($status['maintainedUntil'] !== null && in_array(
            $status['status'],
            [Typo3MajorVersions::STATUS_STABLE, Typo3MajorVersions::STATUS_OLDSTABLE],
            true
        )) {
            $hint = $this->ll('typo3.hint.maintained', date('d.m.Y', $status['maintainedUntil']));
        } elseif ($status['status'] === Typo3MajorVersions::STATUS_UNSUPPORTED && $status['eltsUntil'] !== null) {
            $hint = $this->ll('typo3.hint.ended', date('d.m.Y', $status['eltsUntil']));
        }

        return [
            'status' => $status['status'],
            'label' => $labels[$status['status']] ?? '',
            'colour' => $colours[$status['status']] ?? 'secondary',
            'hint' => $hint,
        ];
    }

    /**
     * Dasselbe für den PHP-Zweig. Grün, solange er aktiv unterstützt wird,
     * gelb in der Phase, in der nur noch Sicherheitsfixes kommen, rot danach.
     *
     * @return array<string, string>
     */
    private function phpBadge(Instance $instance): array
    {
        $status = $this->phpVersions->statusOf($instance->phpVersion);

        $labels = [
            PhpVersions::STATUS_ACTIVE => $this->ll('php.status.active'),
            PhpVersions::STATUS_SECURITY => $this->ll('php.status.security'),
            PhpVersions::STATUS_EOL => $this->ll('php.status.eol'),
        ];
        $colours = [
            PhpVersions::STATUS_ACTIVE => 'success',
            PhpVersions::STATUS_SECURITY => 'warning',
            PhpVersions::STATUS_EOL => 'danger',
        ];

        $hint = '';
        if ($status['status'] === PhpVersions::STATUS_ACTIVE && $status['supportUntil'] !== null) {
            $hint = $this->ll('php.hint.active', date('d.m.Y', $status['supportUntil']));
        } elseif ($status['status'] === PhpVersions::STATUS_SECURITY && $status['eolUntil'] !== null) {
            $hint = $this->ll('php.hint.security', date('d.m.Y', $status['eolUntil']));
        } elseif ($status['status'] === PhpVersions::STATUS_EOL && $status['eolUntil'] !== null) {
            $hint = $this->ll('php.hint.eol', date('d.m.Y', $status['eolUntil']));
        }

        return [
            'status' => $status['status'],
            'cycle' => $status['cycle'],
            'label' => $labels[$status['status']] ?? '',
            'colour' => $colours[$status['status']] ?? 'secondary',
            'hint' => $hint,
        ];
    }

    /**
     * Zwei rote Zustände nebeneinander sagen von sich aus nicht, worin sie
     * sich unterscheiden. Der Hover-Text sagt es.
     *
     * @param array<string, int> $counts
     */
    private function stateHint(string $state, Instance $instance, array $counts): string
    {
        if ($state === 'vulnerable') {
            return $this->ll('state.hint.vulnerable');
        }

        if ($state === 'unsupported') {
            $affected = [];
            if (($counts['typo3Unsupported'] ?? 0) > 0) {
                $affected[] = 'TYPO3 ' . $instance->typo3Major;
            }
            if (($counts['phpUnsupported'] ?? 0) > 0) {
                $affected[] = 'PHP ' . $this->phpBadge($instance)['cycle'];
            }

            return $this->ll('state.hint.unsupported', implode(', ', $affected));
        }

        if ($state === 'incomplete') {
            return $this->ll('state.hint.incomplete');
        }

        if ($state === 'stale') {
            return $this->ll('state.hint.stale');
        }

        return '';
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

        return (string)($GLOBALS['BE_USER']->user['username'] ?? $this->ll('message.unknownUser'));
    }

    private function stateLabel(string $state): string
    {
        // "unsupported" is deliberately not "security hole": all that is
        // known is that nothing arrives any more. And "incomplete" is not
        // "error" — nothing is broken, we just do not know everything, which
        // is a statement of its own and must not pass as an all-clear.
        return in_array($state, ['ok', 'vulnerable', 'unsupported', 'incomplete', 'stale'], true)
            ? $this->ll('state.' . $state)
            : $state;
    }

    private function stateSeverity(string $state): string
    {
        return [
            'ok' => 'success',
            'incomplete' => 'warning',
            'vulnerable' => 'danger',
            'unsupported' => 'danger',
            'stale' => 'danger',
        ][$state] ?? 'default';
    }

    /**
     * A finding title is either text we did not write — an advisory from
     * Packagist, a message from TYPO3's own checks — or one of our keys with
     * its arguments beside it. Only the latter is translated, and a key whose
     * placeholders no longer match its arguments falls back to the plain
     * sentence rather than throwing in the user's face.
     */
    private function findingTitle(string $title, string $arguments): string
    {
        if (!str_starts_with($title, 'LLL:')) {
            return $title;
        }

        $text = $this->getLanguageService()->sL($title);
        $args = $arguments === '' ? [] : json_decode($arguments, true);

        if (!is_array($args) || $args === []) {
            return $text;
        }

        try {
            return vsprintf($text, $args);
        } catch (\Throwable $e) {
            return $text;
        }
    }

    /**
     * @param string|int ...$args
     */
    private function ll(string $key, ...$args): string
    {
        $text = $this->getLanguageService()->sL(self::LL . $key);

        return $args === [] ? $text : vsprintf($text, $args);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * "10.11.18-MariaDB-ubu2204-log" is unusable as a column value.
     */
    private function shortenDbVersion(string $version): string
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }
}
