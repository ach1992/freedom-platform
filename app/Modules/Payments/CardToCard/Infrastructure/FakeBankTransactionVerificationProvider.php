<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Infrastructure;

use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionPage;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionVerificationProvider;
use DomainException;

final class FakeBankTransactionVerificationProvider implements BankTransactionVerificationProvider
{
    /** @var array<string, BankTransactionPage> */
    private array $pages;

    /** @param array<string, BankTransactionPage> $pages */
    public function __construct(private readonly string $providerCode = 'fake', array $pages = [])
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $providerCode) !== 1) {
            throw new DomainException('Fake bank provider code is invalid.');
        }
        $this->pages = $pages;
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function fetch(?string $cursor): BankTransactionPage
    {
        return $this->pages[$cursor ?? ''] ?? new BankTransactionPage([], null);
    }

    public function put(?string $cursor, BankTransactionPage $page): void
    {
        $this->pages[$cursor ?? ''] = $page;
    }
}
