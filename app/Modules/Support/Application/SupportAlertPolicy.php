<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class SupportAlertPolicy
{
    public function __construct(private Repository $config) {}

    public function newTicketEnabled(): bool
    {
        return $this->boolean('support.alerts.new_ticket.enabled');
    }

    public function deliveryFailureEnabled(): bool
    {
        return $this->boolean('support.alerts.delivery_failure.enabled');
    }

    public function slaThresholdSeconds(): ?int
    {
        if (! $this->boolean('support.alerts.sla_delay.enabled')) {
            return null;
        }

        $value = filter_var(
            $this->config->get('support.alerts.sla_delay.threshold_seconds'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 604_800]],
        );
        if ($value === false) {
            throw new RuntimeException(
                'Support SLA-delay alert threshold must be between 60 and 604800 seconds when enabled.',
            );
        }

        return $value;
    }

    private function boolean(string $key): bool
    {
        $value = $this->config->get($key);
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new RuntimeException($key.' must be a boolean.');
        }

        return $parsed;
    }
}
