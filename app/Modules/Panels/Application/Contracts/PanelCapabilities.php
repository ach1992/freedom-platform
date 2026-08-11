<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use InvalidArgumentException;

final readonly class PanelCapabilities
{
    public string $panelType;

    public string $panelVersion;

    /** @var list<string> */
    public array $operations;

    /** @var list<string> */
    public array $protocolProfiles;

    /**
     * @param  array<array-key, mixed>  $operations
     * @param  array<array-key, mixed>  $protocolProfiles
     */
    public function __construct(
        string $panelType,
        string $panelVersion,
        array $operations,
        array $protocolProfiles,
    ) {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $panelType) !== 1) {
            throw new InvalidArgumentException('Panel capability type is invalid.');
        }
        if ($panelVersion === ''
            || $panelVersion !== trim($panelVersion)
            || mb_strlen($panelVersion) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $panelVersion) === 1
        ) {
            throw new InvalidArgumentException('Panel capability version is invalid.');
        }

        $this->panelType = $panelType;
        $this->panelVersion = $panelVersion;
        $this->operations = self::normalizeIdentifiers($operations, 64, 'operation');
        $this->protocolProfiles = self::normalizeIdentifiers($protocolProfiles, 128, 'protocol profile');
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function normalizeIdentifiers(array $values, int $maximumLength, string $label): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Panel capability '.$label.' values must be a list.');
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)
                || preg_match('/\A[a-z0-9_.-]{1,'.$maximumLength.'}\z/', $value) !== 1
            ) {
                throw new InvalidArgumentException('Panel capability '.$label.' is invalid.');
            }
            if (in_array($value, $normalized, true)) {
                throw new InvalidArgumentException('Panel capability '.$label.' is duplicated.');
            }
            $normalized[] = $value;
        }
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
