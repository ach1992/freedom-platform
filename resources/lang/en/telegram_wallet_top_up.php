<?php

return [
    'entry' => 'Increase wallet balance',
    'amount_prompt' => "Wallet top-up\n\nSend the amount in IRR that you want to add to your cash wallet. Persian/Arabic digits and thousands separators are accepted.",
    'invalid_amount' => 'Enter a valid positive IRR amount.',
    'unavailable' => 'Wallet top-up is not available for this account or amount right now.',
    'failed' => 'The payment request failed before settlement. Enter an amount to start a new top-up.',
    'redirect' => "Wallet top-up\n\nAmount: :amount IRR\n\nOpen the secure Zarinpal payment page. After payment, return here and refresh the status. Opening the page is not proof of payment.",
    'pay_button' => 'Pay with Zarinpal',
    'refresh_button' => 'Refresh payment status',
    'pending' => "Wallet top-up\n\nAmount: :amount IRR\n\nThe payment is not settled yet or needs reconciliation. Refresh to check the authoritative provider status.",
    'completed' => "Wallet top-up completed\n\n:amount IRR was settled through the payment authority and posted to your cash wallet.",
];
