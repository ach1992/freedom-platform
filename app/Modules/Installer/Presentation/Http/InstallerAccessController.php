<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Modules\Installer\Application\PhpRuntimePreflight;
use App\Modules\Operations\Application\RuntimeHealthProbe;
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

    public function preflight(
        Request $request,
        PhpRuntimePreflight $runtimePreflight,
        RuntimeHealthProbe $healthProbe,
    ): View {
        $commonExtensions = $this->extensionList(config('installer.php_runtimes.required_extensions.common', []));
        $cliExtensions = array_values(array_unique([
            ...$commonExtensions,
            ...$this->extensionList(config('installer.php_runtimes.required_extensions.cli', [])),
        ]));
        $lsphpExtensions = array_values(array_unique([
            ...$commonExtensions,
            ...$this->extensionList(config('installer.php_runtimes.required_extensions.lsphp', [])),
        ]));

        $runtimes = [
            $runtimePreflight->inspect(
                'cli',
                (string) config('installer.php_runtimes.cli_binary'),
                $cliExtensions,
            ),
            $runtimePreflight->inspect(
                'lsphp',
                (string) config('installer.php_runtimes.lsphp_binary'),
                $lsphpExtensions,
            ),
        ];

        $runtimesPassed = true;

        foreach ($runtimes as $runtime) {
            if (! $runtime['passed']) {
                $runtimesPassed = false;
                break;
            }
        }

        $serviceChecks = $healthProbe->checks();
        $checks = [
            'php_runtimes' => $runtimesPassed,
            'https' => $request->isSecure() || app()->environment('local', 'testing'),
            'database' => $serviceChecks['database']['passed'] ?? false,
            'redis' => $serviceChecks['redis']['passed'] ?? false,
            'storage' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
            'utc' => date_default_timezone_get() === 'UTC',
        ];

        return view('installer.preflight', [
            'checks' => $checks,
            'runtimes' => $runtimes,
        ]);
    }

    /** @return list<string> */
    private function extensionList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $extensions = [];

        foreach ($value as $extension) {
            if (is_string($extension) && $extension !== '') {
                $extensions[] = $extension;
            }
        }

        sort($extensions);

        return array_values(array_unique($extensions));
    }
}
