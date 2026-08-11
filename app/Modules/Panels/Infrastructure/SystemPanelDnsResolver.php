<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelDnsResolver;

final class SystemPanelDnsResolver implements PanelDnsResolver
{
    private const MAX_NAMES = 16;

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $queue = [$host];
        $seen = [];
        $addresses = [];

        while ($queue !== []) {
            if (count($seen) >= self::MAX_NAMES) {
                throw new PanelDnsResolutionException('Panel endpoint DNS alias chain exceeded the safe limit.');
            }

            $name = array_shift($queue);
            if (! is_string($name)) {
                throw new PanelDnsResolutionException('Panel endpoint DNS resolution failed safely.');
            }

            $name = strtolower(rtrim($name, '.'));
            if ($name === '' || filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new PanelDnsResolutionException('Panel endpoint DNS resolution returned an invalid name.');
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $records = @dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME);
            if ($records === false) {
                throw new PanelDnsResolutionException('Panel endpoint DNS resolution failed safely.');
            }

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $type = $record['type'] ?? null;
                if ($type === 'A') {
                    $address = $record['ip'] ?? null;
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $addresses[$address] = true;
                    }

                    continue;
                }

                if ($type === 'AAAA') {
                    $address = $record['ipv6'] ?? null;
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        $addresses[strtolower($address)] = true;
                    }

                    continue;
                }

                if ($type === 'CNAME') {
                    $target = $record['target'] ?? null;
                    if (! is_string($target)) {
                        throw new PanelDnsResolutionException('Panel endpoint DNS resolution returned an invalid alias.');
                    }

                    $target = strtolower(rtrim($target, '.'));
                    if ($target === '' || filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                        throw new PanelDnsResolutionException('Panel endpoint DNS resolution returned an invalid alias.');
                    }
                    if (! isset($seen[$target])) {
                        $queue[] = $target;
                    }
                }
            }
        }

        if ($addresses === []) {
            throw new PanelDnsResolutionException('Panel endpoint DNS resolution returned no usable address.');
        }

        return array_keys($addresses);
    }
}
