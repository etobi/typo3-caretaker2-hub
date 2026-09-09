<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Domain\CleanupService;
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
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The overview: every instance, its state, and the way to connect a new one.
 */
#[AsController]
final class InstanceListController
{
    private const SEVERITY_COLOURS = [
        'critical' => 'danger',
        'high' => 'danger',
        'unknown' => 'danger',
        'medium' => 'warning',
        'low' => 'info',
        'info' => 'secondary',
    ];

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
        private readonly CleanupService $cleanup,
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
     * The full inventory of one instance as the agent delivered it, including
     * the providers that could deliver nothing.
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

        if (!$readOnly && $request->getMethod() === 'POST' && is_array($body) && isset($body['reset'])) {
            $counts = $this->cleanup->resetInstance($instanceId);
            $message = $this->ll('message.reset', $counts['snapshots'], $counts['findings']);
            $messageSeverity = 'success';

            // Everything read below comes from the record we just emptied.
            $instance = $this->instances->findByUid($instanceId) ?? $instance;
        }

        if (!$readOnly && $request->getMethod() === 'POST' && is_array($body) && isset($body['unacknowledge'])) {
            $this->findings->unacknowledge((int)$body['unacknowledge']);
            $message = $this->ll('message.unacknowledged');
            $messageSeverity = 'info';
        }

        if (!$readOnly && $request->getMethod() === 'POST' && ($request->getParsedBody()['trigger'] ?? null) !== null) {
            [$ok, $message] = $this->triggerClient->trigger($instance);
            $messageSeverity = $ok ? 'success' : 'warning';

            $instance = $this->instances->findByUid($instanceId) ?? $instance;
        }

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

        // The current state comes from last_inventory, not from the latest
        // snapshot: snapshots are only written on change and would be stale for
        // everything that depends on the runtime.
        $inventory = $historic !== null
            ? $historic['inventory']
            : $instance->lastInventory;

        $findingCounts = $this->findings->countsForInstance($instanceId);
        $all = $this->findings->findForInstance($instanceId);
        $open = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 0));
        $acknowledged = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 1));

        $view->assignMultiple([
            // With the finding counts, or the detail view would say "OK" where
            // the list says "without security updates".
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
            'generatedAt' => $this->toTimestamp($inventory['generatedAt'] ?? null),
            'schemaVersion' => $inventory['schemaVersion'] ?? null,
            'history' => $this->presentHistory($this->snapshots->findHistory($instanceId), $instanceId, $wanted),
            'snapshotCount' => $this->snapshots->countForInstance($instanceId),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
            'evaluationPending' => $instance->needsEvaluation || $instance->evaluatedAt === 0,
            'evaluatedAt' => $instance->evaluatedAt,
            'findings' => $this->presentFindings($open),
            'acknowledgedFindings' => $this->presentFindings($acknowledged),
            'findingCounts' => $findingCounts,
            'findingsBySeverity' => $this->severityBadges($findingCounts['severities'] ?? []),
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

        $listUri = new Uri((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
        parse_str($listUri->getQuery(), $listParams);

        $filters = $this->filtersFrom($request);
        $matching = $this->applyFilters($presented, $filters);
        $groups = $this->groupInstances($matching);

        $view->assignMultiple([
            'groups' => $groups,
            'showGroupHeadings' => count($groups) > 1 || ($groups[0]['uid'] ?? 0) !== 0,
            'summary' => $this->summarize($instances, $now),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($presented),
            'filterActive' => array_filter($filters) !== [],
            'shownCount' => count($matching),
            'totalCount' => count($presented),
            // Path and query separately: a GET form replaces the whole query
            // string, and the route token in there would go with it — TYPO3
            // then bounces to /typo3/main and renders the backend inside its
            // own frame. The token travels as a hidden field instead.
            'listUri' => $listUri->withQuery(''),
            'listParams' => $listParams,
            // The link needs the token in the URL — it carries no form that
            // could hold it, and without it TYPO3 redirects through
            // /typo3/main and renders the backend inside its own frame.
            'resetUri' => (string)$listUri,
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

        // "OK" is a statement, and it is only true when there is nothing at
        // all. An instance with open findings is not urgent, but it is not
        // done with either.
        if ($state === 'ok' && ($findingCounts['total'] ?? 0) > 0) {
            $state = 'attention';
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
            'phpBranch' => $this->phpBadge($instance)['cycle'],
            'phpSupport' => $this->phpBadge($instance),
            'context' => $instance->applicationContext,
            'siteHosts' => $instance->siteHosts !== []
                ? $instance->siteHosts
                : array_values(array_filter([parse_url($instance->instanceUrl, PHP_URL_HOST)])),
            'siteCount' => $instance->siteCount,
            'agentVersion' => $instance->agentVersion,
            'lastSeen' => $instance->lastSeen,
            'state' => $state,
            'stateLabel' => $this->stateLabel($state),
            'stateSeverity' => $state === 'attention'
                ? (self::SEVERITY_COLOURS[$this->worstSeverity($findingCounts)] ?? 'secondary')
                : $this->stateSeverity($state),
            'stateHint' => $this->stateHint($state, $instance, $findingCounts),
            'findings' => $findingCounts,
            'findingsBySeverity' => $this->severityBadges($findingCounts['severities'] ?? []),
        ];
    }

    /**
     * @param list<Instance> $instances
     * @return array<string, int>
     */
    private function summarize(array $instances, int $now): array
    {
        $summary = ['total' => count($instances), 'ok' => 0, 'attention' => 0, 'incomplete' => 0, 'stale' => 0, 'vulnerable' => 0, 'unsupported' => 0];
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
                    $data[$key] = $this->ll('detail.providers.omittedValue', number_format($size, 0, ',', '.'));
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
     * @return array<string, string>
     */
    private function filtersFrom(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        $filters = [];
        foreach (['state', 'typo3', 'php', 'group'] as $key) {
            $filters[$key] = trim((string)($query['filter_' . $key] ?? ''));
        }

        return $filters;
    }

    /**
     * @param list<array<string, mixed>> $instances
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $instances, array $filters): array
    {
        return array_values(array_filter($instances, static function (array $instance) use ($filters): bool {
            foreach ($filters as $key => $wanted) {
                if ($wanted === '') {
                    continue;
                }

                $value = match ($key) {
                    'state' => (string)$instance['state'],
                    'typo3' => (string)$instance['typo3Major'],
                    'php' => (string)$instance['phpBranch'],
                    'group' => (string)$instance['groupUid'],
                    default => '',
                };

                if ($value !== $wanted) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Only the values that actually occur. A dropdown offering PHP 8.4 when
     * nothing runs it invites a filter that can only come back empty.
     *
     * @param list<array<string, mixed>> $instances
     * @return array<string, list<array<string, string>>>
     */
    private function filterOptions(array $instances): array
    {
        $groups = $this->groups->findAllIndexed();
        $options = ['state' => [], 'typo3' => [], 'php' => [], 'group' => []];

        foreach ($instances as $instance) {
            $options['state'][(string)$instance['state']] = (string)$instance['stateLabel'];

            if ((int)$instance['typo3Major'] > 0) {
                $options['typo3'][(string)$instance['typo3Major']] = 'TYPO3 ' . $instance['typo3Major'];
            }

            if ($instance['phpBranch'] !== '') {
                $options['php'][(string)$instance['phpBranch']] = 'PHP ' . $instance['phpBranch'];
            }

            $groupUid = (string)$instance['groupUid'];
            $options['group'][$groupUid] = $groups[(int)$groupUid]['title'] ?? $this->ll('list.group.ungrouped');
        }

        $out = [];
        foreach ($options as $key => $values) {
            ksort($values, $key === 'state' ? SORT_STRING : SORT_NATURAL);
            $out[$key] = array_map(
                static fn(string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($values),
                array_values($values)
            );
        }

        return $out;
    }

    /**
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

        if ($state === 'attention') {
            return $this->ll('state.hint.attention', $this->ll('finding.severity.' . $this->worstSeverity($counts)));
        }

        if ($state === 'incomplete') {
            return $this->ll('state.hint.incomplete');
        }

        if ($state === 'stale') {
            return $this->ll('state.hint.stale');
        }

        return '';
    }

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
        return in_array($state, ['ok', 'attention', 'vulnerable', 'unsupported', 'incomplete', 'stale'], true)
            ? $this->ll('state.' . $state)
            : $state;
    }

    private function stateSeverity(string $state): string
    {
        return [
            'ok' => 'success',
            'attention' => 'secondary',
            'incomplete' => 'warning',
            'vulnerable' => 'danger',
            'unsupported' => 'danger',
            'stale' => 'danger',
        ][$state] ?? 'default';
    }

    /**
     * @param array<string, mixed> $counts
     */
    private function worstSeverity(array $counts): string
    {
        foreach (array_keys(self::SEVERITY_COLOURS) as $severity) {
            if (($counts['severities'][$severity] ?? 0) > 0) {
                return $severity;
            }
        }

        return 'info';
    }

    /**
     * @param array<string, int> $severities
     * @return list<array<string, string|int>>
     */
    private function severityBadges(array $severities): array
    {
        $badges = [];
        foreach ($severities as $severity => $count) {
            if ($count < 1) {
                continue;
            }

            $label = $this->ll('finding.severity.' . $severity);
            $badges[] = [
                'severity' => $severity,
                'count' => $count,
                'label' => $label,
                'colour' => self::SEVERITY_COLOURS[$severity] ?? 'secondary',
                'hint' => $this->ll('list.findings.bySeverity', $count, $label),
            ];
        }

        return $badges;
    }

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

    private function shortenDbVersion(string $version): string
    {
        // "10.11.18-MariaDB-ubu2204-log" is unusable as a column value.
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }
}
