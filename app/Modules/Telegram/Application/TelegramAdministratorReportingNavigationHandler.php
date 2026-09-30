<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Reporting\Application\DatabaseReportingSnapshotService;
use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportDateRangeResolver;
use App\Modules\Reporting\Application\ReportingDeliveryService;
use App\Modules\Reporting\Application\ReportingPermissions;
use App\Modules\Reporting\Application\ReportPeriod;
use App\Modules\Reporting\Application\ReportScheduleFrequency;
use App\Modules\Reporting\Application\ReportScheduleService;
use App\Modules\Reporting\Application\ReportSnapshot;
use App\Modules\Reporting\Application\ReportTelegramFormatter;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/** Telegram adapter for the permission-aware Reporting application boundary. */
final readonly class TelegramAdministratorReportingNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.reporting';

    private const STATE_MENU = 'admin_reporting_menu';

    private const STATE_RANGE = 'admin_reporting_range';

    private const STATE_CUSTOM_RANGE = 'admin_reporting_custom_range';

    private const STATE_RESULT = 'admin_reporting_result';

    private const STATE_SCHEDULES = 'admin_reporting_schedules';

    private const STATE_SCHEDULE_CREATE = 'admin_reporting_schedule_create';

    private const ACTION_VIEW = 'navigation.admin.reporting.view';

    private const ACTION_PERIOD = 'navigation.admin.reporting.period';

    private const ACTION_CUSTOM_RANGE = 'navigation.admin.reporting.custom_range';

    private const ACTION_EXPORT_CSV = 'navigation.admin.reporting.export_csv';

    private const ACTION_EXPORT_XLSX = 'navigation.admin.reporting.export_xlsx';

    private const ACTION_DELIVER_CHANNEL = 'navigation.admin.reporting.deliver_channel';

    private const ACTION_SCHEDULES = 'navigation.admin.reporting.schedules';

    private const ACTION_SCHEDULE_CREATE = 'navigation.admin.reporting.schedule_create';

    private const ACTION_SCHEDULE_DISABLE = 'navigation.admin.reporting.schedule_disable';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
        private DatabaseReportingSnapshotService $reports,
        private ReportDateRangeResolver $ranges,
        private ReportTelegramFormatter $formatter,
        private ReportingDeliveryService $reportDelivery,
        private ReportScheduleService $schedules,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if ($action->sessionState === 'admin_control') {
            return $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY;
        }

        return in_array($action->sessionState, [
            self::STATE_MENU,
            self::STATE_RANGE,
            self::STATE_CUSTOM_RANGE,
            self::STATE_RESULT,
            self::STATE_SCHEDULES,
            self::STATE_SCHEDULE_CREATE,
        ], true);
    }

    /** @requirement REP-001 REP-002 REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_ENTRY
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram reporting entry is invalid.');
            }
            $this->showMenu($action);

            return;
        }

        match ($action->sessionState) {
            self::STATE_MENU => $this->handleMenu($action),
            self::STATE_RANGE => $this->handleRange($action),
            self::STATE_CUSTOM_RANGE => $this->handleCustomRange($action),
            self::STATE_RESULT => $this->handleResult($action),
            self::STATE_SCHEDULES => $this->handleSchedules($action),
            self::STATE_SCHEDULE_CREATE => $this->handleScheduleCreate($action),
            default => throw new RuntimeException('Telegram reporting state is unsupported.'),
        };
    }

    private function handleMenu(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        match ($action->callbackAction) {
            self::ACTION_VIEW => $this->showRangeMenu($action),
            self::ACTION_SCHEDULES => $this->showSchedules($action),
            default => throw new RuntimeException('Telegram reporting menu action is unsupported.'),
        };
    }

    private function handleRange(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showMenu($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            return;
        }

        if ($action->callbackAction === self::ACTION_CUSTOM_RANGE && $action->callbackPayload === []) {
            $session = $this->transition($action, self::STATE_CUSTOM_RANGE, [], 'custom-range');
            if ($session !== null) {
                $this->renderCustomRangePrompt($action, $session->version, false);
            }

            return;
        }
        if ($action->callbackAction !== self::ACTION_PERIOD) {
            throw new RuntimeException('Telegram reporting range action is unsupported.');
        }

        $period = $action->callbackPayload['period'] ?? null;
        if (! is_string($period) || ! in_array($period, ReportPeriod::presets(), true)) {
            throw new RuntimeException('Telegram reporting period is invalid.');
        }
        $this->showReport($action, $this->ranges->resolve($period));
    }

    private function handleCustomRange(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showRangeMenu($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        try {
            $range = $this->parseCustomRange($action->messageText);
        } catch (DomainException) {
            $this->renderCustomRangePrompt($action, $action->sessionVersion, true);

            return;
        }
        $this->showReport($action, $range);
    }

    private function handleResult(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showRangeMenu($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        $range = $this->rangeFromPayload($action->sessionPayload);
        $locale = $this->locale($action->userId);
        try {
            if ($action->callbackAction === self::ACTION_EXPORT_CSV) {
                $this->reportDelivery->queueExportForAdministratorChat(
                    $action->userId,
                    $action->telegramUserId,
                    $range,
                    'csv',
                    $locale,
                    $this->correlationId($action, 'export-csv'),
                    $action->requestKey.':report-export-csv',
                );
                $this->renderCurrentReport($action, $range, 'export_queued');

                return;
            }
            if ($action->callbackAction === self::ACTION_EXPORT_XLSX) {
                $this->reportDelivery->queueExportForAdministratorChat(
                    $action->userId,
                    $action->telegramUserId,
                    $range,
                    'xlsx',
                    $locale,
                    $this->correlationId($action, 'export-xlsx'),
                    $action->requestKey.':report-export-xlsx',
                );
                $this->renderCurrentReport($action, $range, 'export_queued');

                return;
            }
            if ($action->callbackAction === self::ACTION_DELIVER_CHANNEL) {
                $this->reportDelivery->deliverToConfiguredChannel(
                    $action->userId,
                    $range,
                    $this->correlationId($action, 'channel'),
                    $action->requestKey.':report-channel',
                );
                $this->renderCurrentReport($action, $range, 'channel_queued');

                return;
            }
        } catch (AuthorizationException) {
            $this->returnToAdminControl($action);

            return;
        } catch (DomainException|RuntimeException) {
            $this->renderCurrentReport($action, $range, 'operation_unavailable');

            return;
        }

        throw new RuntimeException('Telegram reporting result action is unsupported.');
    }

    private function handleSchedules(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showMenu($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            return;
        }

        if ($action->callbackAction === self::ACTION_SCHEDULE_CREATE && $action->callbackPayload === []) {
            $session = $this->transition($action, self::STATE_SCHEDULE_CREATE, [], 'schedule-create');
            if ($session !== null) {
                $this->renderScheduleCreatePrompt($action, $session->version, false);
            }

            return;
        }
        if ($action->callbackAction === self::ACTION_SCHEDULE_DISABLE) {
            $schedule = $action->callbackPayload['schedule'] ?? null;
            if (! is_string($schedule) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $schedule) !== 1) {
                throw new RuntimeException('Telegram reporting schedule identity is invalid.');
            }
            try {
                $this->schedules->disableOwnSchedule(
                    $action->userId,
                    $schedule,
                    $this->correlationId($action, 'schedule-disable'),
                    $action->requestKey.':schedule-disable',
                );
                $this->showSchedules($action, 'schedule_disabled');
            } catch (AuthorizationException) {
                $this->returnToAdminControl($action);
            } catch (DomainException|RuntimeException) {
                $this->showSchedules($action, 'operation_unavailable');
            }

            return;
        }

        throw new RuntimeException('Telegram reporting schedules action is unsupported.');
    }

    private function handleScheduleCreate(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showSchedules($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        try {
            [$frequency, $time, $weekday, $dayOfMonth, $period] = $this->parseSchedule($action->messageText);
            $this->schedules->createChannelSchedule(
                $action->userId,
                $period,
                $frequency,
                $time,
                $weekday,
                $dayOfMonth,
                $this->correlationId($action, 'schedule-create'),
                $action->requestKey.':schedule-create',
            );
            $this->showSchedules($action, 'schedule_created');
        } catch (AuthorizationException) {
            $this->returnToAdminControl($action);
        } catch (DomainException|RuntimeException) {
            $this->renderScheduleCreatePrompt($action, $action->sessionVersion, true);
        }
    }

    private function showMenu(TelegramInteractionAction $action): void
    {
        if (! $this->administratorUsers->allowsUser($action->userId, ReportingPermissions::VIEW)) {
            $this->returnToAdminControl($action);

            return;
        }
        $session = $this->transition($action, self::STATE_MENU, [], 'menu');
        if ($session !== null) {
            $this->renderMenu($action, $session->version);
        }
    }

    private function showRangeMenu(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, self::STATE_RANGE, [], 'range');
        if ($session !== null) {
            $this->renderRangeMenu($action, $session->version);
        }
    }

    private function showReport(TelegramInteractionAction $action, ReportDateRange $range): void
    {
        try {
            $snapshot = $this->reports->generate(
                $action->userId,
                $range,
                $this->correlationId($action, 'view'),
                $action->requestKey.':report-view',
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControl($action);

            return;
        }

        $session = $this->transition($action, self::STATE_RESULT, $this->rangePayload($range), 'result');
        if ($session !== null) {
            $this->renderReport($action, $session->version, $snapshot, null);
        }
    }

    private function renderCurrentReport(
        TelegramInteractionAction $action,
        ReportDateRange $range,
        ?string $statusKey,
    ): void {
        try {
            $snapshot = $this->reports->generate(
                $action->userId,
                $range,
                $this->correlationId($action, 'refresh'),
                $action->requestKey.':report-refresh',
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControl($action);

            return;
        }
        $this->renderReport($action, $action->sessionVersion, $snapshot, $statusKey);
    }

    private function showSchedules(TelegramInteractionAction $action, ?string $statusKey = null): void
    {
        try {
            $schedules = $this->schedules->listOwnSchedules($action->userId);
        } catch (AuthorizationException) {
            $this->returnToAdminControl($action);

            return;
        }

        $session = $this->transition($action, self::STATE_SCHEDULES, [], 'schedules');
        if ($session !== null) {
            $this->renderSchedules($action, $session->version, $schedules, $statusKey);
        }
    }

    private function renderMenu(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $locale = $this->locale($action->userId);
        $rows = [[
            $this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_VIEW,
                [],
                'view',
                $this->translation('telegram_reporting.view_button', $locale),
            ),
        ]];
        if ($this->canSchedule($action->userId)) {
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_SCHEDULES,
                [],
                'schedules',
                $this->translation('telegram_reporting.schedules_button', $locale),
            )];
        }
        $rows[] = [$this->backButton($action, $sessionVersion, 'menu', $locale)];

        $this->queue(
            $action,
            $this->translation('telegram_reporting.menu', $locale),
            'menu',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderRangeMenu(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        $pair = [];
        foreach (ReportPeriod::presets() as $period) {
            $pair[] = $this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_PERIOD,
                ['period' => $period],
                'period-'.$period,
                $this->translation('telegram_reporting.periods.'.$period, $locale),
            );
            if (count($pair) === 2) {
                $rows[] = $pair;
                $pair = [];
            }
        }
        if ($pair !== []) {
            $rows[] = $pair;
        }
        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_CUSTOM_RANGE,
            [],
            'custom-range',
            $this->translation('telegram_reporting.custom_range_button', $locale),
        )];
        $rows[] = [$this->backButton($action, $sessionVersion, 'range', $locale)];

        $this->queue(
            $action,
            $this->translation('telegram_reporting.range_prompt', $locale),
            'range',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderCustomRangePrompt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        bool $invalid,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram_reporting.custom_range_prompt', $locale);
        if ($invalid) {
            $text = $this->translation('telegram_reporting.invalid_custom_range', $locale)."\n\n".$text;
        }
        $this->queue(
            $action,
            $text,
            'custom-range',
            new TelegramInlineKeyboardSnapshot([[
                $this->backButton($action, $sessionVersion, 'custom-range', $locale),
            ]]),
        );
    }

    private function renderReport(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ReportSnapshot $snapshot,
        ?string $statusKey,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->formatter->format($snapshot);
        if ($statusKey !== null) {
            $text = $this->translation('telegram_reporting.'.$statusKey, $locale)."\n\n".$text;
        }
        $rows = [];
        $exportButtons = [];
        if ($this->administratorUsers->allowsUser($action->userId, ReportingPermissions::EXPORT)) {
            $exportButtons[] = $this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_EXPORT_CSV,
                [],
                'export-csv',
                $this->translation('telegram_reporting.export_csv_button', $locale),
            );
            $exportButtons[] = $this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_EXPORT_XLSX,
                [],
                'export-xlsx',
                $this->translation('telegram_reporting.export_xlsx_button', $locale),
            );
        }
        if ($exportButtons !== []) {
            $rows[] = $exportButtons;
        }
        if ($this->administratorUsers->allowsUser($action->userId, ReportingPermissions::DELIVER)) {
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_DELIVER_CHANNEL,
                [],
                'deliver-channel',
                $this->translation('telegram_reporting.deliver_channel_button', $locale),
            )];
        }
        $rows[] = [$this->backButton($action, $sessionVersion, 'result', $locale)];

        $this->queue($action, $text, 'result', new TelegramInlineKeyboardSnapshot($rows));
    }

    /**
     * @param  list<array{public_id:string,period:string,frequency:string,run_time_local:string,weekday_iso:int|null,day_of_month:int|null,enabled:bool,next_run_at:string,last_error_code:string|null}>  $schedules
     */
    private function renderSchedules(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $schedules,
        ?string $statusKey,
    ): void {
        $locale = $this->locale($action->userId);
        $lines = [$this->translation('telegram_reporting.schedules_title', $locale)];
        if ($statusKey !== null) {
            array_unshift($lines, $this->translation('telegram_reporting.'.$statusKey, $locale), '');
        }
        if ($schedules === []) {
            $lines[] = '';
            $lines[] = $this->translation('telegram_reporting.schedules_none', $locale);
        }
        $rows = [];
        foreach (array_slice($schedules, 0, 10) as $schedule) {
            $shape = $schedule['frequency'].' · '.$schedule['run_time_local'].' · '.$schedule['period'];
            if ($schedule['weekday_iso'] !== null) {
                $shape .= ' · weekday '.$schedule['weekday_iso'];
            }
            if ($schedule['day_of_month'] !== null) {
                $shape .= ' · day '.$schedule['day_of_month'];
            }
            $lines[] = sprintf(
                '%s · %s · next %s%s',
                substr($schedule['public_id'], 0, 8),
                $shape,
                $schedule['next_run_at'].' UTC',
                $schedule['last_error_code'] === null ? '' : ' · '.$schedule['last_error_code'],
            );
            if ($schedule['enabled']) {
                $rows[] = [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_SCHEDULE_DISABLE,
                    ['schedule' => $schedule['public_id']],
                    'disable-'.$schedule['public_id'],
                    $this->translation('telegram_reporting.disable_schedule_button', $locale, [
                        'id' => substr($schedule['public_id'], 0, 8),
                    ]),
                )];
            }
        }
        if (count($schedules) > 10) {
            $lines[] = '… '.(count($schedules) - 10).' more';
        }
        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_SCHEDULE_CREATE,
            [],
            'schedule-create',
            $this->translation('telegram_reporting.create_schedule_button', $locale),
        )];
        $rows[] = [$this->backButton($action, $sessionVersion, 'schedules', $locale)];

        $this->queue(
            $action,
            implode("\n", $lines),
            'schedules',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderScheduleCreatePrompt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        bool $invalid,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram_reporting.schedule_create_prompt', $locale);
        if ($invalid) {
            $text = $this->translation('telegram_reporting.schedule_invalid', $locale)."\n\n".$text;
        }
        $this->queue(
            $action,
            $text,
            'schedule-create',
            new TelegramInlineKeyboardSnapshot([[
                $this->backButton($action, $sessionVersion, 'schedule-create', $locale),
            ]]),
        );
    }

    private function parseCustomRange(string $input): ReportDateRange
    {
        $parts = preg_split('/\s+/', trim($this->normalizeDigits($input))) ?: [];
        if (count($parts) !== 2) {
            throw new DomainException('Custom report range input is invalid.');
        }
        $timezone = new DateTimeZone('Asia/Tehran');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $parts[0], $timezone);
        $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $parts[1], $timezone);
        if (! $start instanceof DateTimeImmutable
            || ! $endDay instanceof DateTimeImmutable
            || $start->format('Y-m-d') !== $parts[0]
            || $endDay->format('Y-m-d') !== $parts[1]
            || $start > $endDay) {
            throw new DomainException('Custom report range input is invalid.');
        }

        $nowUtc = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($timezone);
        $todayLocal = $nowLocal->setTime(0, 0, 0, 0);
        if ($endDay > $todayLocal) {
            throw new DomainException('Custom report range input is invalid.');
        }
        $endExclusiveUtc = $endDay == $todayLocal
            ? $nowUtc
            : $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));

        return $this->ranges->resolve(
            ReportPeriod::CUSTOM,
            $start->setTimezone(new DateTimeZone('UTC')),
            $endExclusiveUtc,
        );
    }

    /** @return array{0:string,1:string,2:int|null,3:int|null,4:string} */
    private function parseSchedule(string $input): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($this->normalizeDigits($input)))) ?: [];
        $frequency = $parts[0] ?? '';
        if ($frequency === ReportScheduleFrequency::DAILY && count($parts) === 3) {
            return [$frequency, $parts[1], null, null, $parts[2]];
        }
        if ($frequency === ReportScheduleFrequency::WEEKLY && count($parts) === 4) {
            $weekday = filter_var($parts[1], FILTER_VALIDATE_INT);
            if ($weekday === false) {
                throw new DomainException('Weekly report schedule weekday is invalid.');
            }

            return [$frequency, $parts[2], $weekday, null, $parts[3]];
        }
        if ($frequency === ReportScheduleFrequency::MONTHLY && count($parts) === 4) {
            $day = filter_var($parts[1], FILTER_VALIDATE_INT);
            if ($day === false) {
                throw new DomainException('Monthly report schedule day is invalid.');
            }

            return [$frequency, $parts[2], null, $day, $parts[3]];
        }

        throw new DomainException('Report schedule input is invalid.');
    }

    /** @return array{period:string,start_epoch:int|null,end_epoch:int} */
    private function rangePayload(ReportDateRange $range): array
    {
        return [
            'period' => $range->code,
            'start_epoch' => $range->startsAtUtc?->getTimestamp(),
            'end_epoch' => $range->endsBeforeUtc->getTimestamp(),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function rangeFromPayload(array $payload): ReportDateRange
    {
        $period = $payload['period'] ?? null;
        $start = $payload['start_epoch'] ?? null;
        $end = $payload['end_epoch'] ?? null;
        if (! is_string($period)
            || (! in_array($period, ReportPeriod::presets(), true) && $period !== ReportPeriod::CUSTOM)
            || ($start !== null && (! is_int($start) || $start < 0))
            || ! is_int($end)
            || $end < 1
            || ($start !== null && $start >= $end)
        ) {
            throw new RuntimeException('Telegram reporting range state is invalid.');
        }

        return new ReportDateRange(
            $period,
            $start === null ? null : new DateTimeImmutable('@'.$start),
            new DateTimeImmutable('@'.$end),
        );
    }

    private function canSchedule(int $userId): bool
    {
        return $this->administratorUsers->allowsUser($userId, ReportingPermissions::SCHEDULE)
            && $this->administratorUsers->allowsUser($userId, ReportingPermissions::VIEW)
            && $this->administratorUsers->allowsUser($userId, ReportingPermissions::DELIVER);
    }

    private function isBack(TelegramInteractionAction $action): bool
    {
        return ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])
            || $action->kind === TelegramInteractionActionKind::Back
            || preg_match('/\A\/back(?:@[A-Za-z0-9_]+)?\z/u', trim((string) $action->messageText)) === 1;
    }

    private function isEntryCommand(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return $normalized === '/start' || $normalized === '/menu';
    }

    /** @param array<string,mixed> $payload */
    private function transition(
        TelegramInteractionAction $action,
        string $state,
        array $payload,
        string $surface,
    ): ?TelegramInteractionSessionReceipt {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-admin-reporting-'.$surface.':'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram reporting actor binding changed.');
        }

        return $session;
    }

    private function returnToAdminControl(TelegramInteractionAction $action): void
    {
        try {
            $this->navigation->showAdminControl($action);
        } catch (DomainException) {
            // A concurrent accepted interaction already moved the session.
        }
    }

    /** @param array<string,mixed> $payload */
    private function callbackButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        array $payload,
        string $surface,
        string $label,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            $payload,
            'tg-admin-reporting-callback:'.hash('sha256', $surface.':'.$action->requestKey),
        );

        return new TelegramInlineCallbackButton(
            $label,
            $callback->publicId,
            TelegramInlineButtonStyle::Primary,
        );
    }

    private function backButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
        string $locale,
    ): TelegramInlineCallbackButton {
        return $this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'back-'.$surface,
            $this->translation('telegram.navigation.buttons.back', $locale),
        );
    }

    private function queue(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };

        $this->delivery->send(
            $action->telegramUserId,
            $this->presentations->fromSource($source),
            'tg-admin-reporting-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            $this->correlationId($action, $surface),
            $keyboard,
        );
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    private function correlationId(TelegramInteractionAction $action, string $surface): string
    {
        return 'tg-report:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 54);
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === ''
            || $value === '['.$key.']'
            || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1
        ) {
            throw new RuntimeException('Telegram reporting translation is unavailable.');
        }

        return $value;
    }
}
