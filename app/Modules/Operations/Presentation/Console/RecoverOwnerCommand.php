<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Console;

use App\Modules\Operations\Application\OwnerOperatorAuthorityService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class RecoverOwnerCommand extends Command
{
    protected $signature = 'access:owner:recover
        {telegram_user_id : Target Telegram numeric user ID}
        {--expected-current= : Expected current Owner Telegram numeric user ID}
        {--reason= : Required audit reason}
        {--yes : Apply without interactive confirmation}
        {--json : Emit a machine-readable secret-free result}';

    protected $description = 'Safely recover or transfer the database-authoritative Owner from the local CLI';

    public function handle(OwnerOperatorAuthorityService $owners): int
    {
        try {
            $token = config('telegram.bot_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Telegram bot configuration is unavailable.');
            }

            $target = $this->positiveInt($this->argument('telegram_user_id'), 'Target Telegram user ID');
            $current = $owners->currentTelegramUserId($token);

            $expectedOption = $this->option('expected-current');
            $expected = $expectedOption === null
                ? null
                : $this->positiveInt($expectedOption, 'Expected current Owner Telegram user ID');

            if ($current !== null && $expected === null) {
                $this->components->error(
                    'Expected current Owner is required. Re-run with --expected-current='.$current,
                );

                return self::INVALID;
            }

            if ($current === null && $expected !== null) {
                $this->components->error('No current database Owner exists; omit --expected-current.');

                return self::INVALID;
            }

            $reason = $this->option('reason');
            if (! is_string($reason) || trim($reason) === '') {
                $this->components->error('A non-empty --reason is required.');

                return self::INVALID;
            }

            if ($this->option('yes') !== true) {
                $from = $current === null ? 'none' : (string) $current;
                if (! $this->confirm("Transfer database Owner from {$from} to {$target}?")) {
                    $this->info('Owner recovery cancelled.');

                    return self::SUCCESS;
                }
            }

            $result = $owners->recover(
                $token,
                $target,
                $expected,
                $reason,
                (string) config('app.locale', 'fa'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $safe = [
            'status' => 'completed',
            'changed' => $result['changed'],
            'previous_administrator_id' => $result['previous_administrator_id'],
            'administrator_id' => $result['administrator_id'],
            'cancelled_transfer_count' => $result['cancelled_transfer_count'],
        ];

        if ($this->option('json') === true) {
            $this->line(json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info($result['changed'] ? 'Database Owner changed safely.' : 'Database Owner already matches.');

        return self::SUCCESS;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
