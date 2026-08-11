<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelDnsResolver;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelIpAddressPolicy;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use App\Modules\Panels\Infrastructure\PanelConnectPolicyException;
use App\Modules\Panels\Infrastructure\PanelEndpointConnectPolicy;
use App\Modules\Panels\Infrastructure\PanelHttpFailureType;
use App\Modules\Panels\Infrastructure\PanelHttpTransport;
use App\Modules\Panels\Infrastructure\PanelResponseGuard;
use App\Modules\Panels\Infrastructure\PanelResponseRejected;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/** @requirement SEC-001 QUA-001 */
final class PanelHttpSecurityTest extends TestCase
{
    public function test_public_policy_rejects_unsafe_ipv4_and_ipv6_ranges(): void
    {
        $blocked = [
            '0.0.0.0',
            '10.0.0.1',
            '100.64.0.1',
            '127.0.0.1',
            '169.254.169.254',
            '172.16.0.1',
            '192.0.2.1',
            '192.31.196.1',
            '192.52.193.1',
            '192.168.0.1',
            '192.175.48.1',
            '198.18.0.1',
            '198.51.100.1',
            '203.0.113.1',
            '224.0.0.1',
            '255.255.255.255',
            '::',
            '::1',
            '::ffff:127.0.0.1',
            'fe80::1',
            'fc00::1',
            'ff02::1',
            '2001:1::1',
            '2001:db8::1',
            '2002::1',
            '2100::1',
            '3f00::1',
            '3fff::1',
            '2620:4f:8000::1',
        ];

        foreach ($blocked as $address) {
            self::assertFalse(PanelIpAddressPolicy::isPublic($address), $address);
        }

        foreach (['1.1.1.1', '93.184.216.34', '2001:4860:4860::8888', '2606:4700:4700::1111'] as $address) {
            self::assertTrue(PanelIpAddressPolicy::isPublic($address), $address);
        }
    }

    public function test_endpoint_parser_rejects_ambiguous_or_unsafe_urls(): void
    {
        $invalid = [
            ' http://panel.example.test',
            'https://user:secret@panel.example.test',
            'https://panel.example.test/#fragment',
            'https://panel.example.test/?query=1',
            'https://panel.example.test/%2e%2e/admin',
            'https://panel.example.test/%2fadmin',
            'https://panel.example.test\\admin',
            "https://panel.example.test/line\nbreak",
            'https://panel.example.test./api',
        ];

        foreach ($invalid as $url) {
            try {
                PanelEndpoint::fromInput($url);
                self::fail('Unsafe endpoint was accepted: '.$url);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_public_policy_preserves_nonstandard_https_port_and_pins_validated_addresses(): void
    {
        $resolver = new FixedPanelDnsResolver(['2606:4700:4700::1111', '93.184.216.34']);
        $pin = (new PanelEndpointConnectPolicy($resolver))->pin(
            PanelEndpoint::fromInput('https://panel.example.test:8443'),
            PanelNetworkPolicy::PublicOnly,
        );

        self::assertSame(1, $resolver->calls);
        self::assertSame('panel.example.test', $pin->hostname);
        self::assertSame(8443, $pin->port);
        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $pin->validatedAddresses);
        self::assertSame(
            'panel.example.test:8443:93.184.216.34,[2606:4700:4700::1111]',
            $pin->resolveEntry(),
        );
        self::assertSame([$pin->resolveEntry()], $pin->curlOptions()[CURLOPT_RESOLVE]);
    }

    public function test_private_policy_can_pin_approved_private_tls_endpoint(): void
    {
        $resolver = new FixedPanelDnsResolver(['10.20.30.40']);
        $pin = (new PanelEndpointConnectPolicy($resolver))->pin(
            PanelEndpoint::fromInput('https://private-panel.example.test:8443/base'),
            PanelNetworkPolicy::PrivateAllowed,
        );

        self::assertSame('private-panel.example.test', $pin->hostname);
        self::assertSame(8443, $pin->port);
        self::assertSame(['10.20.30.40'], $pin->validatedAddresses);
        self::assertSame('private-panel.example.test:8443:10.20.30.40', $pin->resolveEntry());
    }

    public function test_any_unsafe_dns_candidate_rejects_public_hostname(): void
    {
        $resolver = new FixedPanelDnsResolver(['93.184.216.34', '169.254.169.254']);

        try {
            (new PanelEndpointConnectPolicy($resolver))->pin(
                PanelEndpoint::fromInput('https://panel.example.test'),
                PanelNetworkPolicy::PublicOnly,
            );
            self::fail('Mixed public and unsafe DNS candidates were accepted.');
        } catch (PanelConnectPolicyException $failure) {
            self::assertSame(PanelHttpFailureType::DestinationPolicy, $failure->failure);
            self::assertStringNotContainsString('169.254.169.254', $failure->getMessage());
        }
    }

    public function test_public_dns_candidates_are_pinned_to_original_hostname_for_tls(): void
    {
        $resolver = new FixedPanelDnsResolver(['2606:4700:4700::1111', '93.184.216.34']);
        $pin = (new PanelEndpointConnectPolicy($resolver))->pin(
            PanelEndpoint::fromInput('https://PANEL.Example.Test/base'),
            PanelNetworkPolicy::PublicOnly,
        );

        self::assertSame(1, $resolver->calls);
        self::assertSame('panel.example.test', $pin->hostname);
        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $pin->validatedAddresses);
        self::assertSame(
            'panel.example.test:443:93.184.216.34,[2606:4700:4700::1111]',
            $pin->resolveEntry(),
        );

        $curl = $pin->curlOptions();
        self::assertSame('', $curl[CURLOPT_PROXY]);
        self::assertTrue($curl[CURLOPT_FRESH_CONNECT]);
        self::assertTrue($curl[CURLOPT_FORBID_REUSE]);
        self::assertFalse($curl[CURLOPT_FOLLOWLOCATION]);
        self::assertSame([$pin->resolveEntry()], $curl[CURLOPT_RESOLVE]);
    }

    public function test_programming_failure_from_resolver_is_not_relabelled_as_network_failure(): void
    {
        $resolver = new class implements PanelDnsResolver
        {
            public function resolve(string $host): array
            {
                throw new LogicException('fixture programming defect');
            }
        };

        $this->expectException(LogicException::class);
        (new PanelEndpointConnectPolicy($resolver))->pin(
            PanelEndpoint::fromInput('https://panel.example.test'),
            PanelNetworkPolicy::PublicOnly,
        );
    }

    public function test_response_guard_rejects_compression_and_oversized_bodies(): void
    {
        $guard = new PanelResponseGuard;

        try {
            $guard->assertMetadata('gzip', '128');
            self::fail('Compressed response was accepted.');
        } catch (PanelResponseRejected $failure) {
            self::assertSame(PanelHttpFailureType::Protocol, $failure->failure);
        }

        try {
            $guard->assertMetadata('identity', (string) (PanelResponseGuard::MAX_BYTES + 1));
            self::fail('Oversized response was accepted.');
        } catch (PanelResponseRejected $failure) {
            self::assertSame(PanelHttpFailureType::ResponseTooLarge, $failure->failure);
        }
    }

    public function test_transport_disables_redirects_and_returns_safe_oversize_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://panel.example.test/api/large' => Http::response(
                str_repeat('x', PanelResponseGuard::MAX_BYTES + 1),
                200,
                ['Content-Encoding' => 'identity'],
            ),
        ]);

        $exchange = $this->transport(new FixedPanelDnsResolver(['93.184.216.34']))
            ->request('GET', '/api/large');

        self::assertTrue($exchange->transportFailure);
        self::assertSame(PanelHttpFailureType::ResponseTooLarge, $exchange->failure);
        Http::assertSentCount(1);
    }

    public function test_transport_does_not_follow_redirect_or_forward_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://panel.example.test/api/redirect' => Http::response(
                '',
                302,
                ['Location' => 'https://169.254.169.254/latest/meta-data/'],
            ),
        ]);

        $exchange = $this->transport(new FixedPanelDnsResolver(['93.184.216.34']))
            ->request('GET', '/api/redirect', ['Authorization' => 'Bearer fixture-secret']);

        self::assertSame(302, $exchange->status);
        self::assertFalse($exchange->transportFailure);
        Http::assertSentCount(1);
    }

    public function test_expected_connection_failure_is_typed_and_safe(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://panel.example.test/api/system' => Http::failedConnection('fixture network detail'),
        ]);

        $exchange = $this->transport(new FixedPanelDnsResolver(['93.184.216.34']))
            ->request('GET', '/api/system');

        self::assertTrue($exchange->transportFailure);
        self::assertSame(PanelHttpFailureType::Network, $exchange->failure);
    }

    public function test_expected_timeout_failure_is_typed_deterministically(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://panel.example.test/api/system' => static function (Request $request) {
                return Create::rejectionFor(new GuzzleConnectException(
                    'fixture timeout detail',
                    $request->toPsrRequest(),
                    null,
                    ['errno' => CURLE_OPERATION_TIMEDOUT],
                ));
            },
        ]);

        $exchange = $this->transport(new FixedPanelDnsResolver(['93.184.216.34']))
            ->request('GET', '/api/system');

        self::assertTrue($exchange->transportFailure);
        self::assertSame(PanelHttpFailureType::Timeout, $exchange->failure);
    }

    public function test_configuration_failure_is_not_swallowed_as_transport_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $session = new PanelAdapterSession(
            PanelEndpoint::fromInput('https://panel.example.test'),
            PanelCredentials::fromInput(['token' => 'fixture']),
            new TlsConfiguration(TlsPolicy::CustomCa, 'local', 'missing-fixture-ca.pem', null),
        );
        $transport = new PanelHttpTransport(
            $this->app->make(Factory::class),
            $this->app->make(FilesystemManager::class),
            $session,
            new FixedPanelDnsResolver(['93.184.216.34']),
        );

        $this->expectException(RuntimeException::class);
        $transport->request('GET', '/api/system');
    }

    private function transport(PanelDnsResolver $resolver): PanelHttpTransport
    {
        return new PanelHttpTransport(
            $this->app->make(Factory::class),
            $this->app->make(FilesystemManager::class),
            new PanelAdapterSession(
                PanelEndpoint::fromInput('https://panel.example.test'),
                PanelCredentials::fromInput(['token' => 'fixture']),
                new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
            ),
            $resolver,
        );
    }
}

final class FixedPanelDnsResolver implements PanelDnsResolver
{
    public int $calls = 0;

    /** @param list<string> $addresses */
    public function __construct(private readonly array $addresses) {}

    public function resolve(string $host): array
    {
        $this->calls++;

        return $this->addresses;
    }
}
