<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class ProtocolProfileRecord
{
    public function __construct(
        public int $id,
        public string $code,
        public string $nameFa,
        public ?string $nameEn,
        public string $protocolFamily,
        public ?string $transport,
        public ?string $securityLayer,
        public ?string $host,
        public ?string $sni,
        public ?string $path,
        public ?int $port,
        public ?string $flow,
        public string $state,
        public int $version,
    ) {}
}
