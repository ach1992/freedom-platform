<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Domain\RemoteIdentityDisposition;
use Throwable;

final readonly class PanelCreateCoordinator
{
    public function __construct(private RemoteIdentityResolver $resolver) {}

    /** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
    public function createOrAdopt(PanelAdapter $adapter, PanelCreateServiceRequest $request): PanelOperationResult
    {
        try {
            $before = $this->resolver->resolve($adapter, $request);
            $terminal = $this->terminalResolution($before);
            if ($terminal !== null) {
                return $terminal;
            }

            $created = $adapter->createService($request);
            if ($created->outcome === PanelOperationOutcome::Success) {
                return $this->validateSuccessfulCreate($adapter, $created, $request);
            }
            if ($created->outcome !== PanelOperationOutcome::UncertainResult) {
                return $created;
            }

            $after = $this->resolver->resolve($adapter, $request);
            $resolved = $this->terminalResolution($after);
            if ($resolved !== null) {
                return $resolved;
            }

            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'remote_create_unresolved',
                'Remote create result is uncertain and requires manual review.',
            );
        } catch (Throwable) {
            // Adapter/provider exception text is not a trusted durable-evidence surface and can
            // contain endpoint or credential material. Once create/adopt coordination begins, an
            // unexpected exception is conservatively uncertain and must recover lookup-first.
            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'remote_create_coordinator_exception',
                'Remote create/adopt coordination ended unexpectedly; authoritative reconciliation is required.',
            );
        }
    }

    private function validateSuccessfulCreate(
        PanelAdapter $adapter,
        PanelOperationResult $result,
        PanelCreateServiceRequest $request,
    ): PanelOperationResult {
        if ($result->service === null) {
            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'remote_create_missing_snapshot',
                'Remote create succeeded without an authoritative service snapshot.',
            );
        }

        $resolved = $this->resolver->resolveSnapshot($adapter, $request, $result->service);

        return match ($resolved->disposition) {
            RemoteIdentityDisposition::Adopt => $result,
            RemoteIdentityDisposition::Conflict => new PanelOperationResult(
                PanelOperationOutcome::DefinitiveFailure,
                $result->service,
                $resolved->reasonCode,
                'Created remote service conflicts with the expected service.',
            ),
            RemoteIdentityDisposition::Absent, RemoteIdentityDisposition::ManualReview => new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                $result->service,
                $resolved->reasonCode === 'remote_create_equivalence_unavailable'
                    ? $resolved->reasonCode
                    : 'remote_create_snapshot_unverified',
                'Created remote service could not be verified authoritatively.',
            ),
        };
    }

    private function terminalResolution(RemoteIdentityResolution $resolution): ?PanelOperationResult
    {
        return match ($resolution->disposition) {
            RemoteIdentityDisposition::Absent => null,
            RemoteIdentityDisposition::Adopt => $this->adoptionResult($resolution),
            RemoteIdentityDisposition::Conflict => new PanelOperationResult(
                PanelOperationOutcome::DefinitiveFailure,
                $resolution->service,
                $resolution->reasonCode,
                'Remote identity conflicts with the expected service.',
            ),
            RemoteIdentityDisposition::ManualReview => new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                $resolution->service,
                $resolution->reasonCode,
                'Remote identity could not be established authoritatively.',
            ),
        };
    }

    private function adoptionResult(RemoteIdentityResolution $resolution): PanelOperationResult
    {
        if ($resolution->service === null) {
            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'remote_adoption_missing_service',
                'Remote adoption result is incomplete and requires manual review.',
            );
        }

        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            $resolution->service,
            'remote_service_adopted',
            'Existing remote service was adopted.',
        );
    }
}
