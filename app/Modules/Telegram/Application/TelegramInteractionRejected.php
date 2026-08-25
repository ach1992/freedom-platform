<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;

final class TelegramInteractionRejected extends DomainException {}
