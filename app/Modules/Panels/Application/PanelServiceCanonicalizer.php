<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;

final class PanelServiceCanonicalizer
{
    public function hashCreateRequest(PanelCreateServiceRequest $request): string
    {
        $attributes = $request->validatedAttributes;
        ksort($attributes);

        return hash('sha256', json_encode([
            'username' => $request->username,
            'target_reference' => $request->targetReference,
            'data_limit_bytes' => $request->dataLimitBytes,
            'expires_at_unix' => $request->expiresAt?->getTimestamp(),
            'attributes' => $attributes,
        ], JSON_THROW_ON_ERROR));
    }
}
