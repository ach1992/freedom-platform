<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationRequest;
use DomainException;
use RuntimeException;

final class FakeBlockchainTransactionVerificationProvider implements BlockchainTransactionVerificationProvider
{
    /** @var array<string,UsdtBlockchainVerificationEvidence> */
    private array $evidenceByTxid = [];

    public function __construct(private readonly string $providerCode = 'fake_bep20')
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/', $providerCode) !== 1) {
            throw new DomainException('Fake blockchain verification provider code is invalid.');
        }
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function put(UsdtBlockchainVerificationEvidence $evidence): void
    {
        $txid = strtolower($evidence->txid);
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $txid) !== 1) {
            throw new DomainException('Fake blockchain verification evidence TXID is invalid.');
        }
        $this->evidenceByTxid[$txid] = $evidence;
    }

    public function lookup(UsdtBlockchainVerificationRequest $request): UsdtBlockchainVerificationEvidence
    {
        $txid = strtolower($request->txid);
        $evidence = $this->evidenceByTxid[$txid] ?? null;
        if ($evidence === null) {
            throw new RuntimeException('Fake blockchain verification evidence is not configured for TXID.');
        }
        if (! hash_equals(strtolower($evidence->txid), $txid)) {
            throw new RuntimeException('Fake blockchain verification evidence TXID conflicts with request.');
        }

        return $evidence;
    }
}
