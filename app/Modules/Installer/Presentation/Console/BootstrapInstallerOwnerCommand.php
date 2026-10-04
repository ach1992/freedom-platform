<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Console;

use App\Modules\Operations\Application\OwnerOperatorAuthorityService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class BootstrapInstallerOwnerCommand extends Command
{
    protected $signature = 'installer:bootstrap-owner {--json : Emit a machine-readable secret-free result}';

    protected $description = 'Idempotently establish the initial Owner from protected installer configuration';

    public function handle(OwnerOperatorAuthorityService $owners): int
    {
        try {
            $token = config('telegram.bot_token');
            $ownerId = filter_var(
                config('business.telegram.owner_id'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );

            if (! is_string($token) || $token === '' || $ownerId === false) {
                throw new RuntimeException('Installer Owner configuration is incomplete.');
            }

            $result = $owners->bootstrap(
                $token,
                $ownerId,
                (string) config('app.locale', 'fa'),
            );
        } catch (Throwable) {
            $this->components->error('Initial Owner bootstrap failed.');

            return self::FAILURE;
        }

        $safe = [
            'status' => 'ready',
            'changed' => $result['changed'],
            'administrator_id' => $result['administrator_id'],
        ];

        if ($this->option('json') === true) {
            $this->line(json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info($result['changed'] ? 'Initial Owner established.' : 'Initial Owner already matches.');

        return self::SUCCESS;
    }
}
