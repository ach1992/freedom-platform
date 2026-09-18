<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAdministratorCustomerTargetSearchResult
{
    public function __construct(
        public TelegramAdministratorCustomerTargetSearchDisposition $disposition,
        public ?TelegramAdministratorCustomerTarget $target,
    ) {
        if (($disposition === TelegramAdministratorCustomerTargetSearchDisposition::Matched) !== ($target !== null)) {
            throw new InvalidArgumentException('Telegram administrator customer target search result is invalid.');
        }
    }

    public static function matched(TelegramAdministratorCustomerTarget $target): self
    {
        return new self(TelegramAdministratorCustomerTargetSearchDisposition::Matched, $target);
    }

    public static function notFound(): self
    {
        return new self(TelegramAdministratorCustomerTargetSearchDisposition::NotFound, null);
    }

    public static function ambiguous(): self
    {
        return new self(TelegramAdministratorCustomerTargetSearchDisposition::Ambiguous, null);
    }
}
