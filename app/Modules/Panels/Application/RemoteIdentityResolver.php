<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Domain\RemoteIdentityDisposition;

final readonly class RemoteIdentityResolver
{
    public function __construct(private PanelServiceCanonicalizer $canonicalizer) {}

    /** @requirement PRV-001 SEC-002 QUA-001 */
    public function resolve(PanelAdapter $adapter, PanelCreateServiceRequest $request): RemoteIdentityResolution
    {
        if (! $adapter->capabilities()->supports('authoritative_username_lookup')) {
            return new RemoteIdentityResolution(
                RemoteIdentityDisposition::ManualReview,
                null,
                'authoritative_username_lookup_unavailable',
            );
        }

        try {
            $service = $adapter->findByDeterministicUsername($request->username);
        } catch (AuthoritativePanelLookupUnavailable) {
            return new RemoteIdentityResolution(
                RemoteIdentityDisposition::ManualReview,
                null,
                'authoritative_username_lookup_unavailable',
            );
        }

        if ($service === null) {
            return new RemoteIdentityResolution(RemoteIdentityDisposition::Absent, null, 'remote_service_absent');
        }

        return $this->resolveSnapshot($request, $service);
    }

    public function resolveSnapshot(
        PanelCreateServiceRequest $request,
        RemoteServiceSnapshot $service,
    ): RemoteIdentityResolution {
        if (! hash_equals($request->username, $service->username)) {
            return new RemoteIdentityResolution(
                RemoteIdentityDisposition::Conflict,
                $service,
                'remote_username_mismatch',
            );
        }
        if (! hash_equals($this->canonicalizer->hashCreateRequest($request), $service->canonicalHash)) {
            return new RemoteIdentityResolution(
                RemoteIdentityDisposition::Conflict,
                $service,
                'remote_attributes_mismatch',
            );
        }

        return new RemoteIdentityResolution(RemoteIdentityDisposition::Adopt, $service, 'remote_service_matches');
    }
}
