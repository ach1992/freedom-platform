<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class TelegramSupportContactConfiguration
{
    public TelegramSupportContactDisplayMode $mode;

    public ?string $externalUrl;

    public function __construct(Repository $config)
    {
        $rawMode = $config->get('support.contact.display_mode', TelegramSupportContactDisplayMode::Internal->value);
        if (! is_string($rawMode)) {
            throw new RuntimeException('Telegram Support contact display mode is invalid.');
        }

        $mode = TelegramSupportContactDisplayMode::tryFrom($rawMode);
        if ($mode === null) {
            throw new RuntimeException('Telegram Support contact display mode is invalid.');
        }

        $externalUrl = null;
        if ($mode->showsExternal()) {
            $username = $config->get('support.contact.external_username');
            if (! is_string($username)
                || preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1) {
                throw new RuntimeException('Telegram Support external username is invalid.');
            }

            $externalUrl = 'https://t.me/'.$username;
            TelegramInlineHttpsUrlPolicy::assertAllowed(
                $externalUrl,
                TelegramInlineHttpsUrlPurpose::SupportContact,
            );
        }

        $this->mode = $mode;
        $this->externalUrl = $externalUrl;
    }
}
