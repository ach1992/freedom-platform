<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Http;

use App\Modules\Telegram\Application\Exceptions\InvalidTelegramWebhookPayload;
use App\Modules\Telegram\Application\Exceptions\TelegramUpdateCollision;
use App\Modules\Telegram\Application\TelegramWebhookIngestor;
use App\Shared\Application\SafeLogContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class TelegramWebhookController
{
    /** @requirement ONB-001 PAY-003 SEC-009 */
    public function __construct(private TelegramWebhookIngestor $ingestor) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = $request->attributes->get('correlation_id');
        $correlationId = is_string($correlationId) ? $correlationId : 'unavailable';
        $content = $request->getContent();

        if (! is_string($content)) {
            return new JsonResponse(['ok' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->ingestor->ingest($content, $correlationId);
        } catch (InvalidTelegramWebhookPayload) {
            return new JsonResponse(['ok' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TelegramUpdateCollision) {
            return new JsonResponse(['ok' => false], Response::HTTP_CONFLICT);
        } catch (Throwable $exception) {
            Log::error(
                'Telegram webhook ingestion failed.',
                SafeLogContext::from([
                    'correlation_id' => $correlationId,
                    'error_class' => $exception::class,
                ])->values(),
            );

            return new JsonResponse(['ok' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['ok' => true]);
    }
}
