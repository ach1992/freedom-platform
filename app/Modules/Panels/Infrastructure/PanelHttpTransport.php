<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelDnsResolver;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\TlsPolicy;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final readonly class PanelHttpTransport
{
    private PanelEndpointConnectPolicy $connectPolicy;

    private PanelResponseGuard $responseGuard;

    public function __construct(
        private Factory $http,
        private FilesystemManager $filesystems,
        private PanelAdapterSession $session,
        PanelDnsResolver $dnsResolver,
    ) {
        $this->connectPolicy = new PanelEndpointConnectPolicy($dnsResolver);
        $this->responseGuard = new PanelResponseGuard;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar|array<array-key, mixed>|null>|null  $payload
     */
    public function request(
        string $method,
        string $path,
        array $headers = [],
        ?array $payload = null,
        bool $form = false,
    ): PanelHttpExchange {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported panel HTTP method.');
        }
        $this->assertSafeHeaders($headers);

        try {
            $pin = $this->connectPolicy->pin($this->session->endpoint, $this->session->networkPolicy);
        } catch (PanelConnectPolicyException $failure) {
            return $this->failure($failure->failure);
        }

        try {
            $request = $this->http
                ->acceptJson()
                ->timeout($this->session->timeoutSeconds)
                ->connectTimeout(min(5, $this->session->timeoutSeconds))
                ->withHeaders($headers)
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->withOptions($this->transportOptions($pin));

            if ($form) {
                $request = $request->asForm();
            }

            $url = $this->session->endpoint->value.'/'.ltrim($path, '/');
            $response = match ($method) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $payload ?? []),
                'PUT' => $request->put($url, $payload ?? []),
                'DELETE' => $request->delete($url, $payload ?? []),
            };
        } catch (ConnectionException $exception) {
            $rejected = $this->responseRejection($exception);
            if ($rejected !== null) {
                return $this->failure($rejected->failure);
            }

            return $this->failure($this->classifyTransferFailure($exception));
        } catch (TransferException $exception) {
            $rejected = $this->responseRejection($exception);
            if ($rejected !== null) {
                return $this->failure($rejected->failure);
            }

            return $this->failure($this->classifyTransferFailure($exception));
        }

        try {
            $this->responseGuard->assertMetadata(
                $response->header('Content-Encoding'),
                $response->header('Content-Length'),
            );
        } catch (PanelResponseRejected $rejected) {
            return $this->failure($rejected->failure, $response->status());
        }

        $body = $response->body();
        if (strlen($body) > PanelResponseGuard::MAX_BYTES) {
            return $this->failure(PanelHttpFailureType::ResponseTooLarge, $response->status());
        }

        if (! $response->successful()) {
            return new PanelHttpExchange($response->status(), null, false, false);
        }

        $body = trim($body);
        if ($body === '') {
            return new PanelHttpExchange($response->status(), [], false, false);
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new PanelHttpExchange(
                $response->status(),
                null,
                true,
                false,
                PanelHttpFailureType::Protocol,
            );
        }

        if (! is_array($decoded)) {
            return new PanelHttpExchange(
                $response->status(),
                null,
                true,
                false,
                PanelHttpFailureType::Protocol,
            );
        }

        return new PanelHttpExchange($response->status(), $decoded, false, false);
    }

    /** @param array<string, string> $headers */
    private function assertSafeHeaders(array $headers): void
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower($name), ['host', 'accept-encoding'], true)) {
                throw new InvalidArgumentException('Panel transport does not allow overriding protected headers.');
            }
        }
    }

    /** @return array<string|int, mixed> */
    private function transportOptions(PanelConnectionPin $pin): array
    {
        $options = [
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => false,
            'decode_content' => false,
            'on_headers' => fn (ResponseInterface $response) => $this->responseGuard->onHeaders($response),
            'progress' => fn (
                float $downloadTotal,
                float $downloadedBytes,
                float $uploadTotal,
                float $uploadedBytes,
            ) => $this->responseGuard->progress(
                $downloadTotal,
                $downloadedBytes,
                $uploadTotal,
                $uploadedBytes,
            ),
            'curl' => $pin->curlOptions(),
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
            } catch (InvalidArgumentException|RuntimeException) {
                throw new RuntimeException('Panel custom CA file is unavailable.');
            }

            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                throw new RuntimeException('Panel custom CA file is unavailable.');
            }

            $options['verify'] = $absolutePath;

            return $options;
        }

        $pinHash = $this->session->tls->certificatePinSha256;
        if ($pinHash === null || ! defined('CURLOPT_PINNEDPUBLICKEY')) {
            throw new RuntimeException('Panel certificate pinning is unavailable.');
        }

        $binary = hex2bin($pinHash);
        if ($binary === false) {
            throw new RuntimeException('Panel certificate pin is invalid.');
        }

        /** @var array<int, mixed> $curl */
        $curl = $options['curl'];
        $curl[CURLOPT_PINNEDPUBLICKEY] = 'sha256//'.base64_encode($binary);
        $options['curl'] = $curl;

        return $options;
    }

    private function responseRejection(Throwable $throwable): ?PanelResponseRejected
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PanelResponseRejected) {
                return $current;
            }
        }

        return null;
    }

    private function classifyTransferFailure(Throwable $throwable): PanelHttpFailureType
    {
        $errno = $this->curlErrno($throwable);
        if ($errno === null) {
            return PanelHttpFailureType::Network;
        }

        if ($this->curlErrorMatches($errno, ['CURLE_OPERATION_TIMEDOUT'])) {
            return PanelHttpFailureType::Timeout;
        }
        if ($this->curlErrorMatches($errno, [
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER',
            'CURLE_SSL_CACERT_BADFILE',
            'CURLE_SSL_PINNEDPUBKEYNOTMATCH',
            'CURLE_SSL_INVALIDCERTSTATUS',
            'CURLE_SSL_CLIENTCERT',
        ])) {
            return PanelHttpFailureType::Tls;
        }
        if ($this->curlErrorMatches($errno, [
            'CURLE_UNSUPPORTED_PROTOCOL',
            'CURLE_URL_MALFORMAT',
            'CURLE_WEIRD_SERVER_REPLY',
            'CURLE_HTTP2',
            'CURLE_HTTP2_STREAM',
            'CURLE_HTTP3',
        ])) {
            return PanelHttpFailureType::Protocol;
        }

        return PanelHttpFailureType::Network;
    }

    private function curlErrno(Throwable $throwable): ?int
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof GuzzleConnectException || $current instanceof GuzzleRequestException) {
                $context = $current->getHandlerContext();
                $errno = $context['errno'] ?? null;
                if (is_int($errno)) {
                    return $errno;
                }
            }

            if ($current instanceof ConnectionException && $current->getCode() > 0) {
                return $current->getCode();
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function curlErrorMatches(int $errno, array $names): bool
    {
        foreach ($names as $name) {
            if (defined($name) && constant($name) === $errno) {
                return true;
            }
        }

        return false;
    }

    private function failure(PanelHttpFailureType $failure, ?int $status = null): PanelHttpExchange
    {
        return new PanelHttpExchange($status, null, false, true, $failure);
    }
}
