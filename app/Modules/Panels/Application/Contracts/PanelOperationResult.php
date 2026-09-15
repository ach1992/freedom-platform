<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use InvalidArgumentException;

final readonly class PanelOperationResult
{
    public ?string $providerCode;

    public ?string $safeMessage;

    public function __construct(
        public PanelOperationOutcome $outcome,
        public ?RemoteServiceSnapshot $service,
        ?string $providerCode,
        ?string $safeMessage,
    ) {
        if ($providerCode !== null
            && preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $providerCode) !== 1
        ) {
            throw new InvalidArgumentException('Panel provider result code is invalid.');
        }
        if ($safeMessage !== null
            && ($safeMessage === ''
                || $safeMessage !== trim($safeMessage)
                || mb_strlen($safeMessage) > 1024
                || preg_match('/[\x00-\x1F\x7F]/', $safeMessage) === 1)
        ) {
            throw new InvalidArgumentException('Panel safe result message is invalid.');
        }

        $this->providerCode = $providerCode;
        $this->safeMessage = $safeMessage;
    }
}
