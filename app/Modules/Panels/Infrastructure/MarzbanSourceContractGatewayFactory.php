<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\MarzbanGateway;
use App\Modules\Panels\Application\Contracts\MarzbanGatewayFactory;
use App\Modules\Panels\Application\PanelAdapterSession;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;

final readonly class MarzbanSourceContractGatewayFactory implements MarzbanGatewayFactory
{
    public function __construct(
        private Factory $http,
        private FilesystemManager $filesystems,
    ) {}

    public function make(PanelAdapterSession $session): MarzbanGateway
    {
        return new MarzbanSourceContractGateway(
            new PanelHttpTransport($this->http, $this->filesystems, $session),
            $session,
        );
    }
}
