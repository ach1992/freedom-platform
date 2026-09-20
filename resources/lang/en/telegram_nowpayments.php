<?php

declare(strict_types=1);

return [
    'payment' => "NOWPayments\n\nSend exactly :pay_amount :pay_currency to:\n:pay_address\n\nPricing snapshot: :price_usd USD at :rate_irr IRR (:rate_source).\n\nThe payment is not complete because this screen says so. Only server-side provider verification can settle the purchase. Leaving this screen does not cancel the provider payment.",
    'pending' => "NOWPayments status is still pending.\n\nUse Refresh status to ask the server to re-check the existing provider payment. A new provider payment will not be created.",
    'uncertain' => "NOWPayments requires reconciliation or manual review.\n\nDo not create or pay a second provider payment for this purchase. Use Refresh status; the server will only reconcile the existing authority.",
    'finished' => "NOWPayments verified the payment and the purchase was settled.\n\nSettlement reference: :settlement",
    'unavailable' => 'NOWPayments is not available for this purchase anymore. Choose another currently available payment method if the purchase is still payable.',
    'refresh' => 'Refresh status',
];
