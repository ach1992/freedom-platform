<?php

declare(strict_types=1);

namespace App\Modules\Installer\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Modules\Installer\Application\InstallerEnvironmentPreflight;
use App\Modules\Installer\Application\InstallerFinalizer;
use App\Modules\Installer\Application\PhpRuntimePreflight;
use App\Modules\Operations\Application\RuntimeHealthProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        InstallerEnvironmentPreflight $environmentPreflight,
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
            ...$environmentPreflight->checks(),
            'utc' => date_default_timezone_get() === 'UTC',
        ];

        return view('installer.preflight', [
            'checks' => $checks,
            'runtimes' => $runtimes,
        ]);
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function finalize(Request $request, InstallerFinalizer $finalizer): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['required', 'array', 'min:1', 'max:40'],
            'environment.*' => ['nullable', 'string', 'max:4096'],
        ]);
        $environment = $this->environmentMap($validated['environment'] ?? null);
        $result = $finalizer->finalize($environment);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => $result['status']]);
    }

    /** @return list<string> */
    private function extensionList(mixed $value): array
    {
        $extensions = $this->stringList($value);
        sort($extensions);

        return $extensions;
    }

    /** @return array<string, string> */
    private function environmentMap(mixed $value): array
    {
        $allowedKeys = $this->stringList(config('installer.environment.allowed_keys', []));

        if (! is_array($value) || $allowedKeys === []) {
            throw ValidationException::withMessages([
                'environment' => __('installer.invalid_environment'),
            ]);
        }

        $environment = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)
                || ! in_array($key, $allowedKeys, true)
                || (! is_string($item) && $item !== null)
            ) {
                throw ValidationException::withMessages([
                    'environment' => __('installer.invalid_environment'),
                ]);
            }

            $environment[$key] = $item ?? '';
        }

        return $environment;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }
}
