<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Controller;

use Caretaker2\Hub\Backend\DiffPresenter;
use Caretaker2\Hub\Backend\FindingPresenter;
use Caretaker2\Hub\Backend\InstanceListFilter;
use Caretaker2\Hub\Backend\InstancePresenter;
use Caretaker2\Hub\Backend\InventoryPresenter;
use Caretaker2\Hub\Backend\Labels;
use Caretaker2\Hub\Domain\CleanupService;
use Caretaker2\Hub\Domain\EnrollmentService;
use Caretaker2\Hub\Domain\Instance;
use Caretaker2\Hub\Domain\InstanceRepository;
use Caretaker2\Hub\Domain\InstanceState;
use Caretaker2\Hub\Domain\InventoryDiff;
use Caretaker2\Hub\Domain\InventoryNormalizer;
use Caretaker2\Hub\Domain\SnapshotRepository;
use Caretaker2\Hub\Domain\TriggerClient;
use Caretaker2\Hub\Domain\TriggerException;
use Caretaker2\Hub\Evaluation\FindingRepository;
use Caretaker2\Hub\Http\Origin;
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
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The overview: every instance, its state, and the way to connect a new one.
 */
#[AsController]
final class InstanceListController
{
    private const LANGUAGE_FILE = 'EXT:caretaker2_hub/Resources/Private/Language/locallang.xlf';

    private const ROUTE = 'caretaker2_instances';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly InstanceRepository $instances,
        private readonly EnrollmentService $enrollment,
        private readonly SnapshotRepository $snapshots,
        private readonly UriBuilder $uriBuilder,
        private readonly TriggerClient $triggerClient,
        private readonly FindingRepository $findings,
        private readonly PageRenderer $pageRenderer,
        private readonly ComponentFactory $components,
        private readonly IconFactory $icons,
        private readonly HubTaskInstaller $tasks,
        private readonly CleanupService $cleanup,
        private readonly InstancePresenter $instancePresenter,
        private readonly FindingPresenter $findingPresenter,
        private readonly InventoryPresenter $inventoryPresenter,
        private readonly InstanceListFilter $filter,
        private readonly Labels $labels,
        private readonly InventoryNormalizer $normalizer,
        private readonly InventoryDiff $diff,
        private readonly DiffPresenter $diffPresenter,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $instanceId = (int)($query['instance'] ?? 0);
        $newerSnapshot = (int)($query['diff'] ?? 0);

        if ($instanceId > 0 && $newerSnapshot > 0) {
            return $this->diff($request, $instanceId, $newerSnapshot, (int)($query['against'] ?? 0));
        }

        if ($instanceId > 0) {
            return $this->detail($request, $instanceId);
        }

        return $this->list($request);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $this->labels->get('list.heading'));

        [$enrollmentCode, $message, $messageSeverity] = $this->handleListPost($request);

        $this->pageRenderer->addInlineLanguageLabelFile(self::LANGUAGE_FILE);
        $this->pageRenderer->loadJavaScriptModule('@caretaker2/hub/enroll.js');
        $this->addListButtons($view);

        $now = time();
        $instances = $this->instances->findAll();
        $counts = $this->findings->countsForInstances(
            array_map(static fn(Instance $i): int => $i->uid, $instances)
        );
        $rows = array_map(
            fn(Instance $i): array => $this->instancePresenter->present($i, $now, $counts[$i->uid] ?? []),
            $instances
        );

        $filters = $this->filter->fromRequest($request);
        $matching = $this->filter->apply($rows, $filters);
        $groups = $this->filter->group($matching);

        $listUri = new Uri((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
        parse_str($listUri->getQuery(), $listParams);

        $view->assignMultiple([
            // The address the administrator is reaching the hub on: that is
            // what has to be typed into the agent, and the only value we can
            // be sure about without configuration.
            'hubUrl' => Origin::fromRequest($request),
            'groups' => $groups,
            'showGroupHeadings' => count($groups) > 1 || ($groups[0]['uid'] ?? 0) !== 0,
            'summary' => $this->summarize($rows),
            'filters' => $filters,
            'filterOptions' => $this->filter->options($rows),
            'filterActive' => array_filter($filters) !== [],
            'shownCount' => count($matching),
            'totalCount' => count($rows),
            // Path and query separately: a GET form replaces the whole query
            // string, and the route token in there would go with it — TYPO3
            // then bounces to /typo3/main and renders the backend inside its
            // own frame. The token travels as a hidden field instead.
            'listUri' => $listUri->withQuery(''),
            'listParams' => $listParams,
            // Links need the token in the URL — they carry no form that could
            // hold it.
            'resetUri' => (string)$listUri,
            'enrollmentCode' => $enrollmentCode,
            'schedulerAvailable' => $this->tasks->isAvailable(),
            'missingTasks' => implode(', ', $this->tasks->missing()),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
            'dateTimeFormat' => $this->dateTimeFormat(),
        ]);

        return $view->renderResponse('InstanceList/Index');
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string} enrollment code, message, severity
     */
    private function handleListPost(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if ($request->getMethod() !== 'POST' || !is_array($body)) {
            return [null, null, 'info'];
        }

        if (isset($body['createCode'])) {
            return [$this->enrollment->createCode(), null, 'info'];
        }

        if (isset($body['installTasks'])) {
            try {
                $created = $this->tasks->installMissing();
            } catch (SchedulerTaskException $e) {
                return [null, $this->labels->get($e->labelKey, ...$e->labelArguments), 'danger'];
            }

            return [
                null,
                $created === 1
                    ? $this->labels->get('scheduler.created', $created)
                    : $this->labels->get('scheduler.createdMany', $created),
                'success',
            ];
        }

        return [null, null, 'info'];
    }

    private function addListButtons(ModuleTemplate $view): void
    {
        $view->addButtonToButtonBar(
            $this->components->createGenericButton()
                ->setTag('button')
                ->setLabel($this->labels->get('list.button.addInstance'))
                ->setTitle($this->labels->get('list.button.addInstance'))
                ->setShowLabelText(true)
                ->setIcon($this->icons->getIcon('actions-plus', IconSize::SMALL))
                ->setClasses('btn btn-default')
                ->setAttributes([
                    'type' => 'submit',
                    'form' => 'caretaker2-enroll',
                    'name' => 'createCode',
                    'value' => '1',
                    'data-caretaker2-enroll' => (string)$this->uriBuilder->buildUriFromRoute('ajax_caretaker2_enrollment_code'),
                ])
        );

        $view->addButtonToButtonBar(
            $this->components->createLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => ['tx_caretaker2_group' => [0 => 'new']],
                    'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
                ]))
                ->setTitle($this->labels->get('list.button.addGroup'))
                ->setShowLabelText(true)
                ->setIcon($this->icons->getIcon('actions-plus', IconSize::SMALL))
        );
    }

    /**
     * How many instances are in which state, in the order the states are
     * declared. States nothing is in are left out.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, states: list<array{state: InstanceState, count: int}>}
     */
    private function summarize(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['state']->value] = ($counts[$row['state']->value] ?? 0) + 1;
        }

        $states = [];
        foreach (InstanceState::cases() as $state) {
            if (($counts[$state->value] ?? 0) > 0) {
                $states[] = ['state' => $state, 'count' => $counts[$state->value]];
            }
        }

        return ['total' => count($rows), 'states' => $states];
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

        // An older snapshot is shown as it was: nothing can be changed there.
        $readOnly = $historic !== null;

        [$message, $messageSeverity, $instance] = $readOnly
            ? [null, 'info', $instance]
            : $this->handleDetailPost($request, $instance);

        $this->pageRenderer->addInlineLanguageLabelFile(self::LANGUAGE_FILE);
        $this->pageRenderer->loadJavaScriptModule('@caretaker2/hub/acknowledge.js');
        $this->pageRenderer->loadJavaScriptModule('@caretaker2/hub/busy.js');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $instance->title);

        if (!$readOnly) {
            $this->addSubmitButton($view, 'caretaker2-actions', 'trigger', $this->labels->get('detail.button.refresh'), 'actions-refresh');
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

        $counts = $this->findings->countsForInstances([$instanceId])[$instanceId] ?? [];
        $all = $this->findings->findForInstance($instanceId);
        $open = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 0));
        $acknowledged = array_values(array_filter($all, static fn(array $r): bool => (int)$r['acknowledged'] === 1));

        $view->assignMultiple([
            // With the finding counts, or the detail view would say "OK" where
            // the list says "without security updates".
            'instance' => $this->instancePresenter->present($instance, time(), $counts),
            // Findings always describe the current state, so they are hidden
            // while an older snapshot is on screen rather than shown next to
            // data they do not belong to.
            'historic' => $historic !== null,
            'historicAt' => $historic['crdate'] ?? 0,
            'shown' => $this->inventoryPresenter->summary($inventory),
            'sites' => $this->inventoryPresenter->sites($inventory),
            'currentUri' => (string)$this->uriBuilder->buildUriFromRoute(
                self::ROUTE,
                ['instance' => $instanceId]
            ),
            'providers' => $this->inventoryPresenter->providers($inventory),
            'inventorySize' => $this->inventoryPresenter->size($inventory),
            'generatedAt' => $this->inventoryPresenter->generatedAt($inventory),
            'schemaVersion' => $inventory['schemaVersion'] ?? null,
            'history' => $this->presentHistory($this->snapshots->findHistory($instanceId), $instanceId, $wanted),
            'snapshotCount' => $this->snapshots->countForInstance($instanceId),
            'message' => $message,
            'messageSeverity' => $messageSeverity,
            'evaluationPending' => $instance->needsEvaluation || $instance->evaluatedAt === 0,
            'evaluatedAt' => $instance->evaluatedAt,
            'findings' => $this->findingPresenter->present($open),
            'acknowledgedFindings' => $this->findingPresenter->present($acknowledged),
            'findingsBySeverity' => $this->instancePresenter->severityBadges($counts['severities'] ?? []),
            'hasUnrated' => array_filter($open, static fn(array $r): bool => $r['severity'] === 'unknown') !== [],
            'dateTimeFormat' => $this->dateTimeFormat(),
        ]);

        return $view->renderResponse('InstanceList/Detail');
    }

    /**
     * What changed between two snapshots. Without an explicit older one, the
     * snapshot right before the newer one is taken: "what changed here".
     */
    private function diff(ServerRequestInterface $request, int $instanceId, int $newerUid, int $olderUid): ResponseInterface
    {
        $instance = $this->instances->findByUid($instanceId);
        $history = $this->snapshots->findHistory($instanceId);
        if ($olderUid === 0) {
            $olderUid = $this->predecessorOf($newerUid, $history);
        }

        $newer = $this->snapshots->findInventoryByUid($newerUid, $instanceId);
        $older = $olderUid > 0 ? $this->snapshots->findInventoryByUid($olderUid, $instanceId) : null;

        if ($instance === null || $newer === null || $older === null) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute(
                self::ROUTE,
                $instance === null ? [] : ['instance' => $instanceId]
            ));
        }

        // Compared as the fingerprint sees them: the values that only depend
        // on the collecting runtime would otherwise show up as changes.
        $changes = $this->diff->between(
            $this->normalizer->normalize($older['inventory']),
            $this->normalizer->normalize($newer['inventory'])
        );

        $detailUri = (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, ['instance' => $instanceId]);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Caretaker2', $instance->title . ' · ' . $this->labels->get('diff.heading'));
        $view->addButtonToButtonBar(
            $this->components->createLinkButton()
                ->setHref($detailUri)
                ->setTitle($this->labels->get('diff.back'))
                ->setShowLabelText(true)
                ->setIcon($this->icons->getIcon('actions-view-go-back', IconSize::SMALL))
        );
        $view->getDocHeaderComponent()->setBreadcrumbContext(new BreadcrumbContext(null, [
            new BreadcrumbNode(
                identifier: 'caretaker2-instance-' . $instance->uid,
                label: $instance->title,
                icon: 'caretaker2-module',
                url: $detailUri,
            ),
            new BreadcrumbNode(
                identifier: 'caretaker2-diff',
                label: $this->labels->get('diff.heading'),
                icon: 'actions-history',
            ),
        ]));

        // The same token trick as the list filter: a GET form replaces the
        // query string, so the token rides along as a hidden field.
        $formUri = new Uri((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE));
        parse_str($formUri->getQuery(), $formParams);
        $formParams['instance'] = $instanceId;

        $view->assignMultiple([
            'olderAt' => $older['crdate'],
            'newerAt' => $newer['crdate'],
            'olderUid' => $olderUid,
            'newerUid' => $newerUid,
            'history' => $history,
            'groups' => $this->diffPresenter->present($changes),
            'count' => count($changes),
            'formUri' => $formUri->withQuery(''),
            'formParams' => $formParams,
            'dateTimeFormat' => $this->dateTimeFormat(),
        ]);

        return $view->renderResponse('InstanceList/Diff');
    }

    /**
     * @param list<array{uid: int, crdate: int, fingerprint: string}> $history newest first
     * @return int 0 when there is none
     */
    private function predecessorOf(int $uid, array $history): int
    {
        foreach ($history as $index => $entry) {
            if ($entry['uid'] === $uid) {
                return $history[$index + 1]['uid'] ?? 0;
            }
        }

        return 0;
    }

    /**
     * @return array{0: string|null, 1: string, 2: Instance} message, severity, and the instance as it is afterwards
     */
    private function handleDetailPost(ServerRequestInterface $request, Instance $instance): array
    {
        $body = $request->getParsedBody();
        if ($request->getMethod() !== 'POST' || !is_array($body)) {
            return [null, 'info', $instance];
        }

        if (isset($body['acknowledge'])) {
            $count = $this->findings->acknowledgeMany(
                array_map('intval', (array)($body['findings'] ?? [])),
                $instance->uid,
                $instance->tenant,
                $this->currentUser($request),
                trim((string)($body['note'] ?? ''))
            );

            if ($count === 0) {
                return [$this->labels->get('message.nothingSelected'), 'warning', $instance];
            }

            return [
                $count === 1
                    ? $this->labels->get('message.acknowledged.one')
                    : $this->labels->get('message.acknowledged.many', $count),
                'success',
                $instance,
            ];
        }

        if (isset($body['unacknowledge'])) {
            $taken = $this->findings->unacknowledge((int)$body['unacknowledge'], $instance->uid, $instance->tenant);

            return $taken === 1
                ? [$this->labels->get('message.unacknowledged'), 'info', $instance]
                : [$this->labels->get('message.unacknowledgeMissed'), 'warning', $instance];
        }

        if (isset($body['reset'])) {
            $counts = $this->cleanup->resetInstance($instance->uid);

            return [
                $this->labels->get('message.reset', $counts['snapshots'], $counts['findings']),
                'success',
                $this->reload($instance),
            ];
        }

        if (isset($body['trigger'])) {
            try {
                $stored = $this->triggerClient->trigger($instance);
            } catch (TriggerException $e) {
                return [$this->labels->get($e->labelKey, ...$e->labelArguments), 'warning', $instance];
            }

            return [
                $this->labels->get($stored ? 'trigger.changed' : 'trigger.unchanged'),
                'success',
                $this->reload($instance),
            ];
        }

        return [null, 'info', $instance];
    }

    /**
     * Everything read after a reset or a trigger has to come from the record
     * as it is now, not as it was loaded.
     */
    private function reload(Instance $instance): Instance
    {
        return $this->instances->findByUid($instance->uid) ?? $instance;
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
                label: BackendUtility::datetime($historic['crdate']),
                icon: 'actions-history',
            );
        }

        return $nodes;
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

    /**
     * @param list<array{uid: int, crdate: int, fingerprint: string}> $history
     * @return list<array<string, mixed>>
     */
    private function presentHistory(array $history, int $instanceId, int $current): array
    {
        $latest = $history[0]['uid'] ?? 0;

        return array_map(function (array $entry) use ($instanceId, $current, $latest, $history): array {
            $predecessor = $this->predecessorOf($entry['uid'], $history);

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
                // What changed with this snapshot: the oldest one has nothing to compare with.
                'diffUri' => $predecessor === 0 ? null : (string)$this->uriBuilder->buildUriFromRoute(
                    self::ROUTE,
                    ['instance' => $instanceId, 'diff' => $entry['uid'], 'against' => $predecessor]
                ),
            ];
        }, $history);
    }

    /**
     * Date and time the way the backend is configured to show them.
     */
    private function dateTimeFormat(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'];
    }

    private function currentUser(ServerRequestInterface $request): string
    {
        $user = $request->getAttribute('backend.user');
        if ($user !== null && isset($user->user['username'])) {
            return (string)$user->user['username'];
        }

        return (string)($GLOBALS['BE_USER']->user['username'] ?? $this->labels->get('message.unknownUser'));
    }
}
