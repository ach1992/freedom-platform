<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelDnsResolver;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelIpAddressPolicy;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use InvalidArgumentException;

final readonly class PanelEndpointConnectPolicy
{
    public function __construct(private PanelDnsResolver $resolver) {}

    public function pin(PanelEndpoint $endpoint, PanelNetworkPolicy $policy): PanelConnectionPin
    {
        try {
            $endpoint->assertAllowedBy($policy);
        } catch (InvalidArgumentException) {
            throw new PanelConnectPolicyException(
                PanelHttpFailureType::DestinationPolicy,
                'Panel endpoint destination is not allowed by network policy.',
            );
        }

        if ($endpoint->hostIsIp) {
            $addresses = [$endpoint->host];
        } else {
            try {
                $addresses = $this->resolver->resolve($endpoint->host);
            } catch (PanelDnsResolutionException) {
                throw new PanelConnectPolicyException(
                    PanelHttpFailureType::DnsResolution,
                    'Panel endpoint DNS resolution could not be completed safely.',
                );
            }
        }

        $validated = [];
        foreach ($addresses as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new PanelConnectPolicyException(
                    PanelHttpFailureType::DnsResolution,
                    'Panel endpoint DNS resolution returned an invalid address.',
                );
            }

            $normalized = strtolower($address);
            if ($policy === PanelNetworkPolicy::PublicOnly && ! PanelIpAddressPolicy::isPublic($normalized)) {
                throw new PanelConnectPolicyException(
                    PanelHttpFailureType::DestinationPolicy,
                    'Panel endpoint DNS resolution included a non-public destination.',
                );
            }

            $validated[$normalized] = true;
        }

        if ($validated === []) {
            throw new PanelConnectPolicyException(
                PanelHttpFailureType::DnsResolution,
                'Panel endpoint DNS resolution returned no usable address.',
            );
        }

        $validatedAddresses = array_keys($validated);
        usort($validatedAddresses, static function (string $left, string $right): int {
            $leftIsV6 = str_contains($left, ':');
            $rightIsV6 = str_contains($right, ':');
            if ($leftIsV6 !== $rightIsV6) {
                return $leftIsV6 ? 1 : -1;
            }

            return strcmp($left, $right);
        });

        return new PanelConnectionPin(
            $endpoint->host,
            $endpoint->port,
            $validatedAddresses,
            $endpoint->hostIsIp,
        );
    }
}
