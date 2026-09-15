<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

final readonly class PanelConnectionPin
{
    /** @param list<string> $validatedAddresses */
    public function __construct(
        public string $hostname,
        public int $port,
        public array $validatedAddresses,
        public bool $hostnameIsIp,
    ) {}

    /** @return array<int, mixed> */
    public function curlOptions(): array
    {
        $options = [
            CURLOPT_PROXY => '',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        $resolve = $this->resolveEntry();
        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }

        return $options;
    }

    public function resolveEntry(): ?string
    {
        if ($this->hostnameIsIp) {
            return null;
        }

        $addresses = array_map(
            static fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
            $this->validatedAddresses,
        );

        return $this->hostname.':'.$this->port.':'.implode(',', $addresses);
    }
}
