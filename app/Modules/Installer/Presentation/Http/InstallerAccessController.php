<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Installer\Application\InstallerAccessTokenStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class InstallerAccessController extends Controller
{
    public function showUnlock(): View
    {
        return view('installer.unlock');
    }

    public function unlock(Request $request, InstallerAccessTokenStore $tokens): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:64', 'max:128'],
        ]);

        if (! $tokens->consume($validated['token'])) {
            return back()->withErrors(['token' => __('installer.invalid_token')]);
        }

        $request->session()->regenerate();
        $request->session()->put(
            'installer.unlocked_until',
            now()->addMinutes((int) config('installer.unlock_session_ttl_minutes', 15))->getTimestamp(),
        );

        return redirect()->route('installer.preflight');
    }

    public function preflight(Request $request): View
    {
        $checks = [
            'php' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'extensions' => array_diff(
                ['bcmath', 'curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'redis', 'sodium'],
                get_loaded_extensions(),
            ) === [],
            'https' => $request->isSecure() || app()->environment('local', 'testing'),
            'storage' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
            'utc' => date_default_timezone_get() === 'UTC',
        ];

        return view('installer.preflight', ['checks' => $checks]);
    }
}
