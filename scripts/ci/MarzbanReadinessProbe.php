<?php

declare(strict_types=1);

namespace FreedomPlatform\Scripts\Ci;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final readonly class MarzbanReadinessProbe
{
    public const EXPECTED_VERSION = '0.8.4';

    /**
     * @var Closure(string, string, array<string, string>, array<string, string>|null, bool): array{
     *     status: int|null,
     *     json: array<array-key, mixed>|null,
     *     malformed: bool,
     *     transport_failure: bool
     * }
     */
    private Closure $request;

    /**
     * @param callable(string, string, array<string, string>, array<string, string>|null, bool): array{
     *     status: int|null,
     *     json: array<array-key, mixed>|null,
     *     malformed: bool,
     *     transport_failure: bool
     * } $request
     */
    public function __construct(callable $request)
    {
        $this->request = Closure::fromCallable($request);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     provider: string,
     *     version: string,
     *     base_path: string,
     *     authentication: string,
     *     inbound_count: int,
     *     protocol_count: int,
     *     protocols: list<string>,
     *     sensitive_values: string
     * }
     */
    public function run(array $config): array
    {
        [$origin, $basePath, $username, $password] = $this->validateConfig($config);

        $authentication = $this->expectObject(
            ($this->request)(
                'POST',
                $origin.$basePath.'/api/admin/token',
                ['Accept' => 'application/json'],
                ['username' => $username, 'password' => $password],
                true,
            ),
            'authentication',
        );

        $token = $authentication['access_token'] ?? null;
        if (! is_string($token)
            || $token === ''
            || strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7F]/', $token) === 1
        ) {
            throw new RuntimeException('marzban_readiness_authentication_malformed');
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];

        $system = $this->expectObject(
            ($this->request)('GET', $origin.$basePath.'/api/system', $headers, null, false),
            'system',
        );
        $reportedVersion = $system['version'] ?? null;
        if (! is_string($reportedVersion) || ltrim(trim($reportedVersion), 'vV') !== self::EXPECTED_VERSION) {
            throw new RuntimeException('marzban_readiness_version_mismatch');
        }

        $inbounds = $this->expectObject(
            ($this->request)('GET', $origin.$basePath.'/api/inbounds', $headers, null, false),
            'inbounds',
        );

        $protocols = [];
        $inboundCount = 0;
        foreach ($inbounds as $protocol => $entries) {
            if (! is_string($protocol)
                || $protocol === ''
                || preg_match('/\A[a-z0-9_-]{1,64}\z/', $protocol) !== 1
                || ! is_array($entries)
                || ! array_is_list($entries)
            ) {
                throw new RuntimeException('marzban_readiness_inbounds_shape_invalid');
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)
                    || ! isset($entry['tag'])
                    || ! is_string($entry['tag'])
                    || trim($entry['tag']) === ''
                    || strlen($entry['tag']) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $entry['tag']) === 1
                ) {
                    throw new RuntimeException('marzban_readiness_inbounds_shape_invalid');
                }
                $inboundCount++;
            }

            $protocols[] = $protocol;
        }
        sort($protocols, SORT_STRING);

        return [
            'provider' => 'marzban',
            'version' => self::EXPECTED_VERSION,
            'base_path' => $basePath === '' ? '/' : $basePath,
            'authentication' => 'username_password_bearer',
            'inbound_count' => $inboundCount,
            'protocol_count' => count($protocols),
            'protocols' => $protocols,
            'sensitive_values' => 'redacted',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function validateConfig(array $config): array
    {
        $originValue = trim((string) ($config['origin'] ?? ''));
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($username === ''
            || $username !== trim($username)
            || strlen($username) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
        ) {
            throw new InvalidArgumentException('marzban_readiness_username_invalid');
        }
        if (trim($password) === ''
            || strlen($password) > 4096
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password) === 1
        ) {
            throw new InvalidArgumentException('marzban_readiness_password_invalid');
        }

        $parts = parse_url($originValue);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('marzban_readiness_origin_invalid');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('marzban_readiness_origin_host_invalid');
        }
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('marzban_readiness_origin_host_invalid');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('marzban_readiness_origin_port_invalid');
        }

        $configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($configuredPath === '/') {
            $configuredPath = '';
        }
        if ($configuredPath !== '' && preg_match('#\A/[A-Za-z0-9._~/-]+\z#', $configuredPath) !== 1) {
            throw new InvalidArgumentException('marzban_readiness_base_path_invalid');
        }

        $portSuffix = $port === 443 ? '' : ':'.$port;

        return ['https://'.$host.$portSuffix, $configuredPath, $username, $password];
    }

    /**
     * @param array{
     *     status: int|null,
     *     json: array<array-key, mixed>|null,
     *     malformed: bool,
     *     transport_failure: bool
     * } $exchange
     * @return array<string, mixed>
     */
    private function expectObject(array $exchange, string $operation): array
    {
        if ($exchange['transport_failure']) {
            throw new RuntimeException('marzban_readiness_'.$operation.'_transport_failure');
        }
        if ($exchange['malformed']) {
            throw new RuntimeException('marzban_readiness_'.$operation.'_malformed_response');
        }
        if ($exchange['status'] !== 200) {
            $status = is_int($exchange['status']) ? (string) $exchange['status'] : 'unavailable';
            throw new RuntimeException('marzban_readiness_'.$operation.'_http_'.$status);
        }
        if (! is_array($exchange['json']) || array_is_list($exchange['json'])) {
            throw new RuntimeException('marzban_readiness_'.$operation.'_shape_invalid');
        }

        return $exchange['json'];
    }
}
