<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\AlternativePaymentProviderResolver;
use App\Modules\Payments\CardToCard\Application\CardToCardProviderPollingService;
use App\Modules\Payments\GiftCard\Application\GiftCardPaymentService;
use App\Modules\Payments\GiftCard\Application\GiftCardReconciliationService;
use App\Shared\Application\Clock;
use DateInterval;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class AlternativePaymentRuntimeService
{
    public function __construct(
        private DatabaseManager $database,
        private AlternativePaymentProviderResolver $providers,
        private CardToCardProviderPollingService $cardToCardPolling,
        private GiftCardPaymentService $giftCards,
        private GiftCardReconciliationService $giftCardReconciliation,
        private Clock $clock,
    ) {}

    /** @requirement C2C-003 C2C-005 GFT-003 GFT-004 PAY-002 PAY-003 INT-001 INT-002 QUA-004 */
    public function run(int $limit): AlternativePaymentRuntimeResult
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Alternative-payment runtime limit must be between 1 and 500.');
        }

        $c2cProvidersPolled = 0;
        $c2cTransactionsIngested = 0;
        $c2cMatches = 0;
        $c2cCaptures = 0;
        $c2cReviews = 0;
        $giftCardSubmissionsProcessed = 0;
        $giftCardSubmissionsReconciled = 0;
        $failures = 0;

        $providerCodes = $this->database->connection()
            ->table('c2c_destination_accounts')
            ->where('state', 'active')
            ->where('verification_provider_code', '<>', 'manual')
            ->distinct()
            ->orderBy('verification_provider_code')
            ->limit(50)
            ->pluck('verification_provider_code')
            ->all();

        foreach ($providerCodes as $providerCode) {
            if (! is_string($providerCode) || $providerCode === '') {
                $failures++;

                continue;
            }
            try {
                $provider = $this->providers->bank($providerCode);
                if ($provider === null) {
                    $failures++;

                    continue;
                }
                $summary = $this->cardToCardPolling->poll(
                    $provider,
                    $this->correlationId('c2c', $providerCode),
                );
                $c2cProvidersPolled++;
                $c2cTransactionsIngested += $summary['ingested'];
                $c2cMatches += $summary['matched'];
                $c2cCaptures += $summary['captured'];
                $c2cReviews += $summary['reviewed'];
            } catch (Throwable) {
                $failures++;
            }
        }

        $automaticGiftCards = $this->database->connection()
            ->table('gift_card_submissions as submission')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->where('submission.state', 'submitted')
            ->where('type.verification_mode', '<>', 'manual_only')
            ->orderBy('submission.id')
            ->limit($limit)
            ->get(['submission.public_id', 'type.provider_code']);

        foreach ($automaticGiftCards as $submission) {
            try {
                $providerCode = (string) $submission->provider_code;
                $provider = $this->providers->giftCard($providerCode);
                if ($provider === null) {
                    $failures++;

                    continue;
                }
                $this->giftCards->process(
                    (string) $submission->public_id,
                    $provider,
                    $this->correlationId('gift-process', (string) $submission->public_id),
                );
                $giftCardSubmissionsProcessed++;
            } catch (Throwable) {
                $failures++;
            }
        }

        $cutoff = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->sub(new DateInterval('PT15M'))
            ->format('Y-m-d H:i:s.u');

        $reconciliationCandidates = $this->database->connection()
            ->table('gift_card_submissions as submission')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->where('type.verification_mode', '<>', 'manual_only')
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('submission.state', ['validating', 'reserving', 'redeeming'])
                    ->orWhere(function ($captured) use ($cutoff): void {
                        $captured->where('submission.state', 'captured')
                            ->whereNotExists(function ($events) use ($cutoff): void {
                                $events->selectRaw('1')
                                    ->from('gift_card_provider_events as runtime_status')
                                    ->whereColumn('runtime_status.gift_card_submission_id', 'submission.id')
                                    ->where('runtime_status.operation', 'status')
                                    ->where('runtime_status.created_at', '>=', $cutoff);
                            });
                    });
            })
            ->orderBy('submission.id')
            ->limit($limit)
            ->get(['submission.public_id', 'type.provider_code']);

        foreach ($reconciliationCandidates as $submission) {
            try {
                $providerCode = (string) $submission->provider_code;
                $provider = $this->providers->giftCard($providerCode);
                if ($provider === null) {
                    $failures++;

                    continue;
                }
                $this->giftCardReconciliation->reconcile(
                    (string) $submission->public_id,
                    $provider,
                    $this->correlationId('gift-reconcile', (string) $submission->public_id),
                );
                $giftCardSubmissionsReconciled++;
            } catch (Throwable) {
                $failures++;
            }
        }

        return new AlternativePaymentRuntimeResult(
            $c2cProvidersPolled,
            $c2cTransactionsIngested,
            $c2cMatches,
            $c2cCaptures,
            $c2cReviews,
            $giftCardSubmissionsProcessed,
            $giftCardSubmissionsReconciled,
            $failures,
        );
    }

    private function correlationId(string $operation, string $identity): string
    {
        return 'altpay:'.$operation.':'.substr(hash('sha256', $identity.'|'.$this->clock->now()->format('U.u')), 0, 24);
    }
}
