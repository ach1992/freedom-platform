<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface OperationalAlertRecorder
{
    /**
     * @param  array<string, bool|float|int|string|null>  $safeContext
     */
    public function raise(
        string $severity,
        string $eventName,
        string $deduplicationKey,
        string $correlationId,
        array $safeContext = [],
    ): void;

    /**
     * Persist the alert identity at most once. Existing active or resolved
     * records remain unchanged so periodic scanners do not manufacture
     * repeated occurrences from one durable condition.
     *
     * @param  array<string, bool|float|int|string|null>  $safeContext
     */
    public function raiseOnce(
        string $severity,
        string $eventName,
        string $deduplicationKey,
        string $correlationId,
        array $safeContext = [],
    ): void;

    public function resolve(string $eventName, string $deduplicationKey): void;
}
