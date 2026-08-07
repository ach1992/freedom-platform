<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Domain\RemoteIdentityDisposition;
use Throwable;

final readonly class RemoteIdentityResolver
{
    /** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
    public function resolve(PanelAdapter $adapter, PanelCreateServiceRequest $request): RemoteIdentityResolution
    {
        if (! $adapter->capabilities()->supports('authoritative_username_lookup')) {
            return $this->manualReview('authoritative_username_lookup_unavailable');
        }

        $expectedHash = $this->expectedCreateEquivalenceHash($adapter, $request);
        if ($expectedHash === null) {
            return $this->manualReview('remote_create_equivalence_unavailable');
        }

        try {
            $service = $adapter->findByDeterministicUsername($request->username);
        } catch (AuthoritativePanelLookupUnavailable) {
            return $this->manualReview('authoritative_username_lookup_unavailable');
        }

        if ($service === null) {
            return new RemoteIdentityResolution(RemoteIdentityDisposition::Absent, null, 'remote_service_absent');
        }

        return $this->resolveSnapshotWithExpectedHash($request, $service, $expectedHash);
    }

    /** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
    public function resolveSnapshot(
        PanelAdapter $adapter,
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

        $expectedHash = $this->expectedCreateEquivalenceHash($adapter, $request);
        if ($expectedHash === null) {
            return $this->manualReview('remote_create_equivalence_unavailable', $service);
        }

        return $this->resolveSnapshotWithExpectedHash($request, $service, $expectedHash);
    }

    private function resolveSnapshotWithExpectedHash(
        PanelCreateServiceRequest $request,
        RemoteServiceSnapshot $service,
        string $expectedHash,
    ): RemoteIdentityResolution {
        if (! hash_equals($request->username, $service->username)) {
            return new RemoteIdentityResolution(
                RemoteIdentityDisposition::Conflict,
                $service,
                'remote_username_mismatch',
            );
        }
        if ($service->createEquivalenceHash === null) {
            return $this->manualReview('remote_create_equivalence_unavailable', $service);
        }
        if (! hash_equals($expectedHash, $service->createEquivalenceHash)) {
            return new RemoteIdentityResolution(
                RemoteIdentityDisposition::Conflict,
                $service,
                'remote_attributes_mismatch',
            );
        }

        return new RemoteIdentityResolution(RemoteIdentityDisposition::Adopt, $service, 'remote_service_matches');
    }

    private function expectedCreateEquivalenceHash(
        PanelAdapter $adapter,
        PanelCreateServiceRequest $request,
    ): ?string {
        try {
            $hash = strtolower(trim($adapter->createEquivalenceHash($request)));
        } catch (Throwable) {
            return null;
        }

        return preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 ? $hash : null;
    }

    private function manualReview(
        string $reasonCode,
        ?RemoteServiceSnapshot $service = null,
    ): RemoteIdentityResolution {
        return new RemoteIdentityResolution(
            RemoteIdentityDisposition::ManualReview,
            $service,
            $reasonCode,
        );
    }
}
