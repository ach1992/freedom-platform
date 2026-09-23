<?php

declare(strict_types=1);

return [
    'entry' => 'Transfer wallet balance',
    'recipient_prompt' => "Wallet transfer\n\nSend the recipient's exact @username, Telegram numeric ID, or application public ID. Only an active customer on this bot can receive a transfer.",
    'recipient_not_found' => "No eligible recipient matched that exact identity.\n\nSend an exact @username, Telegram numeric ID, or application public ID.",
    'recipient_ambiguous' => "That identity is ambiguous.\n\nUse the recipient's exact @username or application public ID.",
    'amount_prompt' => "Wallet transfer\n\nRecipient: :recipient\n\nSend the IRR amount to transfer. Persian/Arabic digits and thousands separators are accepted.",
    'amount_invalid' => "The transfer amount is invalid.\n\nRecipient: :recipient\nSend a positive IRR integer.",
    'amount_unavailable' => "That transfer cannot be prepared under the current balance or transfer policy.\n\nRecipient: :recipient\nYou can send a different amount.",
    'amount_expired' => "The previous confirmation expired and its hold was released.\n\nRecipient: :recipient\nSend an amount again.",
    'confirm' => "Confirm wallet transfer\n\nRecipient: :recipient\nAmount: :amount IRR\nFee: :fee IRR\nTotal debit: :total IRR\nAvailable after hold: :available IRR\nConfirmation expires: :expires_at\n\nCurrent policy and recipient/account state are revalidated when you confirm.",
    'confirm_button' => 'Confirm transfer',
    'cancel_button' => 'Cancel transfer',
    'completed_sender' => "Wallet transfer completed.\n\nRecipient: :recipient\nAmount: :amount IRR\nFee: :fee IRR\n\nReplay will not create another ledger effect.",
    'completed_recipient' => "You received :amount IRR in your cash wallet.\nSender reference: …:sender",
    'home' => 'Home',
];
