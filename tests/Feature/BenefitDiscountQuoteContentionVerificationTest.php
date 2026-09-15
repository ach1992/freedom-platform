<?php

declare(strict_types=1);

namespace {
    use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
    use App\Modules\Orders\Application\QuoteDiscountAuthorization;
    use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--benefit-discount-quote-contention-worker') {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = $app->make(DatabaseManager::class);
        $database->connection()->statement('SET SESSION innodb_lock_wait_timeout = 5');

        echo "READY\n";
        flush();
        $encoded = fgets(STDIN);
        if ($encoded === false) {
            fwrite(STDERR, "Discount Quote contention payload was not provided.\n");
            exit(2);
        }
        $decoded = base64_decode(trim($encoded), true);
        if ($decoded === false) {
            fwrite(STDERR, "Invalid Discount Quote contention payload encoding.\n");
            exit(2);
        }
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            $authorizationPayload = $payload['authorization'];
            if (! is_array($authorizationPayload)) {
                throw new RuntimeException('Discount Quote contention authorization payload is invalid.');
            }
            $authorization = new QuoteDiscountAuthorization(
                (string) $authorizationPayload['grant_public_id'],
                (string) $authorizationPayload['grant_configuration_hash'],
                (string) $authorizationPayload['resolution_public_id'],
                (string) $authorizationPayload['resolution_configuration_hash'],
                (string) $authorizationPayload['source_quote_public_id'],
                (string) $authorizationPayload['source_quote_configuration_hash'],
                (string) $authorizationPayload['rule_code'],
                (int) $authorizationPayload['discount_irr'],
                false,
            );
            $result = $database->connection()->transaction(function () use ($app, $payload, $authorization): array {
                $quote = $app->make(QuoteService::class)->create(
                    (string) $payload['quote_key'],
                    (int) $payload['user_id'],
                    (int) $payload['plan_offering_id'],
                    new QuotePricingInput(
                        QuoteOverrideSource::None,
                        null,
                        null,
                        $authorization->ruleCode,
                        $authorization->discountIrr,
                        new DateTimeImmutable((string) $payload['expires_at'], new DateTimeZone('UTC')),
                    ),
                    (string) $payload['correlation_id'],
                );
                $consumption = $app->make(QuoteDiscountAuthority::class)->consume(new QuoteDiscountConsumptionRequest(
                    (string) $payload['consumption_key'],
                    (int) $payload['user_id'],
                    $authorization,
                    $quote->quotePublicId,
                    $quote->configurationSnapshotHash,
                    (string) $payload['correlation_id'],
                ));

                return [
                    'quote_public_id' => $quote->quotePublicId,
                    'consumption_public_id' => $consumption->consumptionPublicId,
                ];
            }, 3);
            echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n";
        }
        exit(0);
    }
}

namespace Tests\Feature {
    use App\Modules\Catalog\Application\CatalogChangeContext;
    use App\Modules\Catalog\Application\PlanOfferingService;
    use App\Modules\Catalog\Domain\ProductVisibility;
    use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
    use App\Modules\Orders\Application\QuoteDiscountAuthorization;
    use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
    use App\Modules\Orders\Application\QuotePricingInput;
    use App\Modules\Orders\Application\QuoteService;
    use App\Modules\Orders\Domain\QuoteOverrideSource;
    use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
    use DateTimeImmutable;
    use DateTimeZone;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use RuntimeException;
    use Tests\Support\CreatesBenefitCodeFixtures;
    use Tests\TestCase;

    /** @requirement BUY-002 PRO-001 PRO-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 */
    final class BenefitDiscountQuoteContentionVerificationTest extends TestCase
    {
        use CreatesBenefitCodeFixtures;
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 20;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed();
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateTablesForAllConnections();
            } finally {
                parent::tearDown();
            }
        }

        public function test_competing_quote_consumptions_for_one_grant_commit_exactly_one_discounted_quote(): void
        {
            $offering = $this->activeBenefitOffering('discount-contention');
            $version = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
            $this->app->make(PlanOfferingService::class)->setVisibility(
                $offering['id'],
                $version,
                ProductVisibility::Visible,
                new CatalogChangeContext(
                    'discount-contention-visible-0001',
                    'discount-contention-visible-correlation',
                    'discount_contention_test',
                    'Expose the contention Offering to the customer catalog.',
                    $this->benefitOwner(),
                ),
            );
            $userId = $this->benefitUser('customer');
            $rule = $this->usageRule($offering['id'], 'discount.rule.contention', 75_000);
            $this->benefitCampaign(
                'benefit.discount.contention',
                BenefitCodeType::DiscountGrant,
                $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
                'discount-contention',
            );
            $issued = $this->benefitIssue('benefit.discount.contention', 'discount-contention');
            $code = (string) $issued->items[0]->fullCode;
            $sourceQuote = $this->app->make(QuoteService::class)->create(
                'discount-contention-source-quote-0001',
                $userId,
                $offering['id'],
                new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->utcNow()->modify('+30 minutes')),
                'discount-contention-source-correlation',
            );
            $authorization = $this->app->make(QuoteDiscountAuthority::class)->authorize(new QuoteDiscountAuthorizationRequest(
                'discount-contention-auth-0001',
                $userId,
                $sourceQuote->quotePublicId,
                $sourceQuote->configurationSnapshotHash,
                $code,
                'discount-contention-auth-correlation',
            ));
            self::assertSame(1, DB::table('benefit_code_discount_grants')->count());
            self::assertSame(1, DB::table('pricing_rule_resolutions')->count());
            self::assertSame(1, DB::table('quotes')->count());

            $results = $this->runConcurrent([
                $this->payload($userId, $offering['id'], $authorization, 'first'),
                $this->payload($userId, $offering['id'], $authorization, 'second'),
            ]);

            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['ok'] === false));
            self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
            self::assertSame(2, DB::table('quotes')->count());
            $discountedQuotes = DB::table('quotes')->whereNotNull('discount_reference_code')->get(['discount_irr', 'final_price_irr']);
            self::assertCount(1, $discountedQuotes);
            self::assertSame(75_000, (int) $discountedQuotes[0]->discount_irr);
            self::assertSame(925_000, (int) $discountedQuotes[0]->final_price_irr);
            self::assertSame(0, DB::table('orders')->count());
            self::assertSame(0, DB::table('payment_intents')->count());
            self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        }

        /** @return array<string,mixed> */
        private function payload(int $userId, int $offeringId, QuoteDiscountAuthorization $authorization, string $suffix): array
        {
            return [
                'user_id' => $userId,
                'plan_offering_id' => $offeringId,
                'quote_key' => 'discount-contention-quote-'.$suffix.'-0001',
                'consumption_key' => 'discount-contention-consume-'.$suffix.'-0001',
                'correlation_id' => 'discount-contention-'.$suffix.'-correlation',
                'expires_at' => $this->utcNow()->modify('+15 minutes')->format(DATE_ATOM),
                'authorization' => [
                    'grant_public_id' => $authorization->grantPublicId,
                    'grant_configuration_hash' => $authorization->grantConfigurationHash,
                    'resolution_public_id' => $authorization->resolutionPublicId,
                    'resolution_configuration_hash' => $authorization->resolutionConfigurationHash,
                    'source_quote_public_id' => $authorization->sourceQuotePublicId,
                    'source_quote_configuration_hash' => $authorization->sourceQuoteConfigurationHash,
                    'rule_code' => $authorization->ruleCode,
                    'discount_irr' => $authorization->discountIrr,
                ],
            ];
        }

        /**
         * @param  list<array<string,mixed>>  $payloads
         * @return list<array<string,mixed>>
         */
        private function runConcurrent(array $payloads): array
        {
            /** @var list<array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:string}> $workers */
            $workers = [];
            try {
                foreach ($payloads as $payload) {
                    $pipes = [];
                    $process = proc_open([
                        PHP_BINARY,
                        '-d',
                        'pcov.enabled=0',
                        __FILE__,
                        '--benefit-discount-quote-contention-worker',
                    ], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes, dirname(__DIR__, 2));
                    if (! is_resource($process)) {
                        throw new RuntimeException('Unable to start Discount Quote contention worker.');
                    }
                    /** @var array{0:resource,1:resource,2:resource} $pipes */
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $workers[] = [
                        'process' => $process,
                        'pipes' => $pipes,
                        'payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ];
                }
                foreach ($workers as $index => $worker) {
                    if ($this->readLine($worker, 'readiness', $index) !== "READY\n") {
                        throw new RuntimeException('Discount Quote contention worker returned an invalid readiness marker.');
                    }
                }
                foreach ($workers as $worker) {
                    fwrite($worker['pipes'][0], $worker['payload']."\n");
                    fflush($worker['pipes'][0]);
                    fclose($worker['pipes'][0]);
                }

                $results = [];
                foreach ($workers as $index => $worker) {
                    $line = $this->readLine($worker, 'result', $index);
                    $stderr = stream_get_contents($worker['pipes'][2]);
                    fclose($worker['pipes'][1]);
                    fclose($worker['pipes'][2]);
                    if (proc_close($worker['process']) !== 0) {
                        throw new RuntimeException('Discount Quote contention worker failed: '.$stderr);
                    }
                    /** @var array<string,mixed> $result */
                    $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $results[] = $result;
                }

                return $results;
            } finally {
                $this->terminateWorkers($workers);
            }
        }

        /** @param array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:string} $worker */
        private function readLine(array $worker, string $phase, int $index): string
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            $stderr = '';
            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for Discount Quote contention output.');
                }
                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }
                    $line = fgets($stream);
                    if ($line !== false) {
                        return $line;
                    }
                }
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException('Discount Quote contention worker exited before '.$phase.' output: '.$stderr);
                }
            }

            throw new RuntimeException(sprintf(
                'Discount Quote contention worker %d timed out during %s after %d seconds: %s',
                $index,
                $phase,
                self::WORKER_TIMEOUT_SECONDS,
                $stderr,
            ));
        }

        /** @param list<array{process:resource,pipes:array{0:resource,1:resource,2:resource},payload:string}> $workers */
        private function terminateWorkers(array $workers): void
        {
            foreach ($workers as $worker) {
                if (! is_resource($worker['process'])) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
        }

        private function utcNow(): DateTimeImmutable
        {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
    }
}
