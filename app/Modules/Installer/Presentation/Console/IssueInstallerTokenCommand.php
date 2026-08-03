<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Console;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use Illuminate\Console\Command;

final class IssueInstallerTokenCommand extends Command
{
    protected $signature = 'installer:issue-token {--ttl=30 : Validity in minutes (5-60)}';

    protected $description = 'Issue a short-lived one-time installer token for entry through the browser form';

    public function handle(InstallerAccessTokenStore $tokens): int
    {
        if (is_file((string) config('installer.lock_path'))) {
            $this->error('Installer is permanently locked. Use the separately reviewed recovery procedure.');

            return self::FAILURE;
        }

        $ttl = filter_var($this->option('ttl'), FILTER_VALIDATE_INT);
        $minimum = (int) config('installer.minimum_token_ttl_minutes', 5);
        $maximum = (int) config('installer.maximum_token_ttl_minutes', 60);

        if (! is_int($ttl) || $ttl < $minimum || $ttl > $maximum) {
            $this->error("TTL must be between {$minimum} and {$maximum} minutes.");

            return self::INVALID;
        }

        $token = $tokens->issue($ttl);

        $this->warn('Sensitive one-time installer token. Do not paste it into chat, logs, or a URL.');
        $this->line($token);
        $this->info("Expires in {$ttl} minutes and is consumed by the first successful unlock.");

        return self::SUCCESS;
    }
}
