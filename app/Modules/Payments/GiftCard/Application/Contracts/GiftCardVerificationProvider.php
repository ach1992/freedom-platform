<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application\Contracts;

interface GiftCardVerificationProvider
{
    public function code(): string;

    public function capabilities(): GiftCardProviderCapabilities;

    public function validate(GiftCardProviderRequest $request): GiftCardProviderEvidence;

    public function reserve(GiftCardProviderRequest $request): GiftCardProviderEvidence;

    public function redeem(GiftCardProviderRequest $request): GiftCardProviderEvidence;

    public function release(GiftCardProviderRequest $request): GiftCardProviderEvidence;

    public function status(GiftCardProviderRequest $request): GiftCardProviderEvidence;
}
