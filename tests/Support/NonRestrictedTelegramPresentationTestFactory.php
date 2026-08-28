<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use LogicException;
use ReflectionClass;
use stdClass;

final class NonRestrictedTelegramPresentationTestFactory
{
    public static function plainText(string $text): NonRestrictedTelegramPresentation
    {
        $reflection = new ReflectionClass(NonRestrictedTelegramPresentation::class);
        $capabilityProperty = $reflection->getProperty('sourceCapability');
        $capability = $capabilityProperty->getValue();
        if (! is_object($capability)) {
            $capability = new stdClass;
            $capabilityProperty->setValue($capability);
        }

        $validated = $reflection->getMethod('validated')->invoke(null, $text, $capability);
        if (! $validated instanceof NonRestrictedTelegramPresentation) {
            throw new LogicException('Unable to create trusted Telegram presentation test fixture.');
        }

        return $validated;
    }
}
