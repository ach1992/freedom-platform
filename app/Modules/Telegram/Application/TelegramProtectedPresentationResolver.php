<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use RuntimeException;

final readonly class TelegramProtectedPresentationResolver
{
    public function __construct(
        private TelegramCustomerPurchaseCardToCardPayment $cardToCardPayments,
        private Translator $translator,
        private ?TelegramMembershipJoinPresentationResolver $membershipJoinPresentations = null,
    ) {}

    public function resolveForSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        if ($userId < 1) {
            throw new RuntimeException('Protected Telegram presentation reference is unavailable.');
        }
        if ($reference->isMembershipJoinPrompt()) {
            if ($this->membershipJoinPresentations === null) {
                throw new RuntimeException('Protected Telegram membership presentation resolver is unavailable.');
            }

            return $this->membershipJoinPresentations->resolveForSelf($userId, $reference);
        }
        if (! $reference->isCardToCardDestination()) {
            throw new RuntimeException('Protected Telegram presentation reference is unavailable.');
        }

        try {
            $destination = $this->cardToCardPayments->destinationForSelf(
                $userId,
                $userId,
                $reference->publicId,
            );
        } catch (AuthorizationException $exception) {
            throw new DomainException('Protected Telegram presentation is no longer authorized.', previous: $exception);
        }
        $cardNumber = $destination->cardNumber->reveal();
        $text = $this->translation(
            'telegram.navigation.purchase.payment_methods.card_to_card_payment.protected_instructions',
            $reference->locale,
            [
                'card_number' => $this->formatCardNumber($cardNumber),
                'amount' => number_format($destination->payableAmountIrr, 0, '.', ','),
                'currency' => 'IRR',
                'expires_at' => $destination->expiresAt->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s'),
            ],
        );
        $text .= "\n\n".$this->translation(
            'telegram_c2c_receipt.upload_prompt',
            $reference->locale,
        );
        $copyLabel = $this->translation(
            'telegram.navigation.purchase.payment_methods.card_to_card_payment.copy_card',
            $reference->locale,
        );

        return ProtectedTelegramPresentation::plainTextWithCopyButton($text, $copyLabel, $cardNumber);
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $text = $this->translator->get($key, $replace, $locale);
        if (! is_string($text) || $text === '' || $text === $key) {
            $text = $this->translator->get($key, $replace, 'en');
        }
        if (! is_string($text) || $text === '' || $text === $key) {
            throw new RuntimeException('Protected Telegram translation is unavailable.');
        }

        return $text;
    }

    private function formatCardNumber(string $cardNumber): string
    {
        if (preg_match('/\A[0-9]{16}\z/', $cardNumber) !== 1) {
            throw new RuntimeException('Protected card-to-card destination is invalid.');
        }

        return implode(' ', str_split($cardNumber, 4));
    }
}
