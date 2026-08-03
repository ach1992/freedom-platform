<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-ID');
        $externalRequestId = is_string($incoming) && preg_match('/\A[a-zA-Z0-9._:-]{8,64}\z/', $incoming) === 1
            ? $incoming
            : null;
        $correlationId = (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('external_request_id', $externalRequestId);
        Log::shareContext(array_filter([
            'correlation_id' => $correlationId,
            'external_request_id' => $externalRequestId,
        ]));

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
