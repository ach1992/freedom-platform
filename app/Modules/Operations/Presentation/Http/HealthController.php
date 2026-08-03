<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Application\RuntimeHealthProbe;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'alive',
            'release' => config('app.version', 'unversioned'),
        ]);
    }

    public function ready(RuntimeHealthProbe $probe): JsonResponse
    {
        $checks = $probe->checks();
        $healthy = $probe->isHealthy($checks);

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'release' => config('app.version', 'unversioned'),
        ], $healthy ? 200 : 503);
    }
}
