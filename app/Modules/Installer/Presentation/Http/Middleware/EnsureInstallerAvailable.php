<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Http\Middleware;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureInstallerAvailable
{
    public function __construct(private InstallerAccessTokenStore $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (is_file((string) config('installer.lock_path')) || ! $this->tokens->exists()) {
            abort(404);
        }

        return $next($request);
    }
}
