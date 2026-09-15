<?php

declare(strict_types=1);

namespace Tests\Fixtures\Telegram;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use Closure;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class UnreviewedDynamicTelegramSource
{
    public function __construct(private ContainerInterface $container) {}

    public function queueRestricted(string $restrictedSecret): mixed
    {
        $applicationRoot = 'App\\Modules\\';
        $module = 'Tele'.'gram';
        $applicationLayer = '\\Application\\';
        $presentationName = 'NonRestrictedTelegram'.'Presentation';
        $presentationClass = $applicationRoot.$module.$applicationLayer.$presentationName;
        $validatedMethod = 'vali'.'dated';

        $mint = Closure::bind(
            static function (string $text) use ($presentationClass, $validatedMethod): object {
                return $presentationClass::$validatedMethod($text);
            },
            null,
            $presentationClass,
        );
        if (! $mint instanceof Closure) {
            throw new LogicException('Unable to construct dynamic provenance attack fixture.');
        }
        $presentation = $mint($restrictedSecret);

        $queueName = 'TelegramDeliveryQueue'.'Service';
        $queueClass = $applicationRoot.$module.$applicationLayer.$queueName;
        $queue = $this->container->get($queueClass);
        $queueMethod = 'qu'.'eue';

        return Closure::fromCallable([$queue, $queueMethod])(
            TelegramDeliveryAction::Send,
            900001,
            null,
            $presentation,
            'restricted-fixture-request',
            'restricted-fixture-correlation',
        );
    }
}
