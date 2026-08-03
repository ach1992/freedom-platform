<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstallerUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $unlockedUntil = $request->session()->get('installer.unlocked_until');

        if (! is_int($unlockedUntil) || $unlockedUntil <= now()->getTimestamp()) {
            $request->session()->forget('installer.unlocked_until');

            return redirect()->route('installer.unlock');
        }

        return $next($request);
    }
}
