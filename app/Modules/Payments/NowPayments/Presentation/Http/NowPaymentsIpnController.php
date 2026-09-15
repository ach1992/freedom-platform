<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Presentation\Http;

use App\Modules\Payments\NowPayments\Application\NowPaymentsIpnIngressService;
use DomainException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NowPaymentsIpnController
{
    public function __invoke(Request $request, Container $container): JsonResponse
    {
        if (! (bool) config('services.nowpayments.enabled', false)) {
            return response()->json(['status' => 'provider_disabled'], 404);
        }
        $signature = $request->header('x-nowpayments-sig');
        if (! is_string($signature) || trim($signature) === '') {
            return response()->json(['status' => 'invalid_ipn'], 401);
        }

        try {
            $ingress = $container->make(NowPaymentsIpnIngressService::class);
            $receipt = $ingress->handle(
                $request->getContent(),
                $signature,
                (string) Str::uuid(),
            );
        } catch (DomainException) {
            return response()->json(['status' => 'invalid_ipn'], 401);
        } catch (RuntimeException) {
            return response()->json(['status' => 'provider_reconciliation_required'], 503);
        } catch (Throwable) {
            return response()->json(['status' => 'provider_reconciliation_required'], 503);
        }

        return response()->json([
            'status' => 'accepted',
            'payment_state' => $receipt->state->value,
            'settlement_public_id' => $receipt->settlementPublicId,
        ]);
    }
}
