<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\TlsPolicy;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

final readonly class PanelHttpTransport
{
    public function __construct(
        private Factory $http,
        private FilesystemManager $filesystems,
        private PanelAdapterSession $session,
    ) {}

    /**
     * @param array<string, string> $headers
     * @param array<string, scalar|array<array-key, mixed>|null>|null $payload
     */
    public function request(
        string $method,
        string $path,
        array $headers = [],
        ?array $payload = null,
        bool $form = false,
    ): PanelHttpExchange {
        try {
            $request = $this->http
                ->acceptJson()
                ->timeout($this->session->timeoutSeconds)
                ->connectTimeout(min(5, $this->session->timeoutSeconds))
                ->withHeaders($headers)
                ->withOptions($this->transportOptions());

            if ($form) {
                $request = $request->asForm();
            }

            $url = $this->session->endpoint->value.'/'.ltrim($path, '/');
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $payload ?? []),
                'PUT' => $request->put($url, $payload ?? []),
                'DELETE' => $request->delete($url, $payload ?? []),
                default => throw new RuntimeException('Unsupported panel HTTP method.'),
            };
        } catch (ConnectionException) {
            return new PanelHttpExchange(null, null, false, true);
        } catch (Throwable) {
            return new PanelHttpExchange(null, null, false, true);
        }

        $body = trim($response->body());
        if ($body === '') {
            return new PanelHttpExchange($response->status(), [], false, false);
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new PanelHttpExchange($response->status(), null, true, false);
        }

        if (! is_array($decoded)) {
            return new PanelHttpExchange($response->status(), null, true, false);
        }

        return new PanelHttpExchange($response->status(), $decoded, false, false);
    }

    /** @return array<string|int, mixed> */
    private function transportOptions(): array
    {
        $options = [
            'allow_redirects' => false,
            'verify' => true,
        ];

        if ($this->session->tls->policy === TlsPolicy::SystemCa) {
            return $options;
        }

        if ($this->session->tls->policy === TlsPolicy::CustomCa) {
            $disk = $this->session->tls->customCaDisk;
            $path = $this->session->tls->customCaPath;
            if ($disk === null || $path === null) {
                throw new RuntimeException('Panel custom CA configuration is incomplete.');
            }

            try {
                $absolutePath = $this->filesystems->disk($disk)->path($path);
            } catch (Throwable) {
                throw new RuntimeException('Panel custom CA file is unavailable.');
            }

            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                throw new RuntimeException('Panel custom CA file is unavailable.');
            }

            $options['verify'] = $absolutePath;

            return $options;
        }

        $pin = $this->session->tls->certificatePinSha256;
        if ($pin === null || ! defined('CURLOPT_PINNEDPUBLICKEY')) {
            throw new RuntimeException('Panel certificate pinning is unavailable.');
        }

        $binary = hex2bin($pin);
        if ($binary === false) {
            throw new RuntimeException('Panel certificate pin is invalid.');
        }

        $options['curl'] = [
            CURLOPT_PINNEDPUBLICKEY => 'sha256//'.base64_encode($binary),
        ];

        return $options;
    }
}
