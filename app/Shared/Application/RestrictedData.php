<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Marker for values that must never cross routine observability or durable
 * payload boundaries without an explicit protected reveal operation.
 */
interface RestrictedData {}
