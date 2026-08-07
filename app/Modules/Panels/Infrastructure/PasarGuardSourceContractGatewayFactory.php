<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PasarGuardGateway;
use App\Modules\Panels\Application\Contracts\PasarGuardGatewayFactory;
use App\Modules\Panels\Application\PanelAdapterSession;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;

final readonly class PasarGuardSourceContractGatewayFactory implements PasarGuardGatewayFactory
{
    public function __construct(
        private Factory $http,
        private FilesystemManager $filesystems,
    ) {}

    public function make(PanelAdapterSession $session): PasarGuardGateway
    {
        return new PasarGuardSourceContractGateway(
            new PanelHttpTransport($this->http, $this->filesystems, $session),
            $session,
        );
    }
}
