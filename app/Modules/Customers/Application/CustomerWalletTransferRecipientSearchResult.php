<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use InvalidArgumentException;

final readonly class CustomerWalletTransferRecipientSearchResult
{
    private const MATCHED = 'matched';

    private const NOT_FOUND = 'not_found';

    private const AMBIGUOUS = 'ambiguous';

    private function __construct(
        public string $status,
        public ?CustomerWalletTransferRecipient $recipient,
    ) {
        if (! in_array($status, [self::MATCHED, self::NOT_FOUND, self::AMBIGUOUS], true)
            || (($status === self::MATCHED) !== ($recipient !== null))) {
            throw new InvalidArgumentException('Wallet transfer recipient search result is invalid.');
        }
    }

    public static function matched(CustomerWalletTransferRecipient $recipient): self
    {
        return new self(self::MATCHED, $recipient);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null);
    }

    public static function ambiguous(): self
    {
        return new self(self::AMBIGUOUS, null);
    }

    public function isMatched(): bool
    {
        return $this->status === self::MATCHED;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::AMBIGUOUS;
    }
}
