<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Exceptions;

use RuntimeException;

final class TelegramUpdateCollision extends RuntimeException {}
