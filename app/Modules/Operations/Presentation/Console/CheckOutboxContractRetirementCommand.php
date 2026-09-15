<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Shared\Infrastructure\DatabaseOutboxContractRetirementGuard;
use Illuminate\Console\Command;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** @requirement ARCH-004 OPS-003 QUA-004 */
final class CheckOutboxContractRetirementCommand extends Command
{
    protected $signature = 'operations:check-outbox-contract-retirement
        {event-type : Durable Outbox event type}
        {version : Positive durable contract version}
        {--json : Emit JSON only}';

    protected $description = 'Verify that one Outbox contract version has no unprocessed durable messages';

    public function handle(DatabaseOutboxContractRetirementGuard $guard): int
    {
        $eventType = $this->argument('event-type');
        $rawVersion = $this->argument('version');
        $version = filter_var($rawVersion, FILTER_VALIDATE_INT);

        if (! is_string($eventType) || $eventType === '' || $version === false) {
            return $this->result(self::INVALID, 'invalid', 'outbox_contract_retirement_invalid_input');
        }

        try {
            $guard->assertRetirable($eventType, $version);
        } catch (InvalidArgumentException) {
            return $this->result(self::INVALID, 'invalid', 'outbox_contract_retirement_invalid_input');
        } catch (LogicException) {
            return $this->result(self::FAILURE, 'blocked', 'outbox_contract_retirement_pending_messages');
        } catch (Throwable) {
            return $this->result(self::FAILURE, 'failed', 'outbox_contract_retirement_check_failed');
        }

        return $this->result(self::SUCCESS, 'safe', 'outbox_contract_retirement_safe');
    }

    private function result(int $exitCode, string $status, string $code): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['status' => $status, 'code' => $code], JSON_THROW_ON_ERROR));
        } elseif ($exitCode === self::SUCCESS) {
            $this->info('Outbox contract retirement is safe for this event type and version.');
        } else {
            $this->error('Outbox contract retirement is not currently safe.');
        }

        return $exitCode;
    }
}
