<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Presentation\Http;

use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

final class ZarinpalCallbackController
{
    public function __invoke(Request $request, ZarinpalPaymentService $payments): JsonResponse
    {
        $authority = $request->query('Authority');
        $status = $request->query('Status');
        if (! is_string($authority) || ! is_string($status)) {
            return response()->json(['status' => 'invalid_callback'], 422);
        }

        try {
            $receipt = $payments->handleCallback(
                $authority,
                $status,
                (string) Str::uuid(),
            );
        } catch (DomainException) {
            return response()->json(['status' => 'invalid_callback'], 422);
        } catch (RuntimeException) {
            return response()->json(['status' => 'payment_review_required'], 409);
        }

        return response()->json([
            'status' => $receipt->state->value,
            'settlement_public_id' => $receipt->purchaseSettlementPublicId,
        ], $receipt->state === ZarinpalRequestState::Verified ? 200 : 202);
    }
}
