<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\TlsConfiguration;
use InvalidArgumentException;

final readonly class PanelAdapterSession
{
    public function __construct(
        public PanelEndpoint $endpoint,
        public PanelCredentials $credentials,
        public TlsConfiguration $tls,
        public int $timeoutSeconds = 15,
        public int $maximumAttempts = 3,
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('Panel timeout must be between 1 and 120 seconds.');
        }
        if ($maximumAttempts < 1 || $maximumAttempts > 5) {
            throw new InvalidArgumentException('Panel maximum attempts must be between 1 and 5.');
        }
    }
}
