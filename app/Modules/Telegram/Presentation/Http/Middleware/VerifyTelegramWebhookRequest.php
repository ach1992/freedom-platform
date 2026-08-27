<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Http\Middleware;

use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class VerifyTelegramWebhookRequest
{
    /** @requirement SEC-001 SEC-009 */
    public function __construct(private TelegramRuntime $configuration) {}

    public function handle(Request $request, Closure $next): Response
    {
        $providedSecret = $request->headers->get('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($providedSecret) || ! hash_equals($this->configuration->webhookSecret(), $providedSecret)) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        if ($contentType !== 'application/json') {
            return new JsonResponse(['ok' => false], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $maximumBodyBytes = $this->configuration->maximumBodyBytes();
        $contentLength = $request->headers->get('Content-Length');

        if (is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > $maximumBodyBytes
        ) {
            return new JsonResponse(['ok' => false], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $content = $request->getContent();

        if (! is_string($content) || strlen($content) > $maximumBodyBytes) {
            return new JsonResponse(['ok' => false], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }
}
