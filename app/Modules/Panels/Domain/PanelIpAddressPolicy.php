<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

final class PanelIpAddressPolicy
{
    /** @var list<array{string, int}> */
    private const IPV4_BLOCKED = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.31.196.0', 24],
        ['192.52.193.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['192.175.48.0', 24],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /**
     * IANA-allocated global-unicast IPv6 blocks that are suitable for ordinary
     * public endpoints. Unlisted space inside 2000::/3 remains reserved.
     *
     * @var list<array{string, int}>
     */
    private const IPV6_PUBLIC_ALLOCATED = [
        ['2001:200::', 23],
        ['2001:400::', 23],
        ['2001:600::', 23],
        ['2001:800::', 22],
        ['2001:c00::', 23],
        ['2001:e00::', 23],
        ['2001:1200::', 23],
        ['2001:1400::', 22],
        ['2001:1800::', 23],
        ['2001:1a00::', 23],
        ['2001:1c00::', 22],
        ['2001:2000::', 19],
        ['2001:4000::', 23],
        ['2001:4200::', 23],
        ['2001:4400::', 23],
        ['2001:4600::', 23],
        ['2001:4800::', 23],
        ['2001:4a00::', 23],
        ['2001:4c00::', 23],
        ['2001:5000::', 20],
        ['2001:8000::', 19],
        ['2001:a000::', 20],
        ['2001:b000::', 20],
        ['2003::', 18],
        ['2400::', 12],
        ['2410::', 12],
        ['2600::', 12],
        ['2610::', 23],
        ['2620::', 23],
        ['2630::', 12],
        ['2800::', 12],
        ['2a00::', 12],
        ['2a10::', 12],
        ['2c00::', 12],
    ];

    /** @var list<array{string, int}> */
    private const IPV6_SPECIAL_WITHIN_PUBLIC_ALLOCATIONS = [
        ['2001:db8::', 32],
        ['2620:4f:8000::', 48],
    ];

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::IPV4_BLOCKED as [$network, $prefix]) {
                if (self::inCidr($address, $network, $prefix)) {
                    return false;
                }
            }

            return true;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $allocated = false;
        foreach (self::IPV6_PUBLIC_ALLOCATED as [$network, $prefix]) {
            if (self::inCidr($address, $network, $prefix)) {
                $allocated = true;
                break;
            }
        }
        if (! $allocated) {
            return false;
        }

        foreach (self::IPV6_SPECIAL_WITHIN_PUBLIC_ALLOCATIONS as [$network, $prefix]) {
            if (self::inCidr($address, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $address, string $network, int $prefix): bool
    {
        $packedAddress = inet_pton($address);
        $packedNetwork = inet_pton($network);
        if ($packedAddress === false || $packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $maximumPrefix = strlen($packedAddress) * 8;
        if ($prefix < 0 || $prefix > $maximumPrefix) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
