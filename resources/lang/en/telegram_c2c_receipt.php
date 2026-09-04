<?php

declare(strict_types=1);

return [
    'upload_prompt' => 'After making the transfer, send the bank receipt here as a photo or image file. Uploading a receipt only submits evidence for review; it does not confirm or settle the payment.',
    'received' => "Your receipt was recorded and is pending review.\n\nUploading a receipt does not confirm payment by itself. Payment status changes only after valid bank evidence is reviewed. Send /menu to continue.",
    'invalid' => "This file cannot be accepted as a receipt image. Send a valid JPEG, PNG, or WebP image within the allowed size.\n\nNo payment confirmation or settlement was created from this file.",
    'unavailable' => "This receipt cannot be submitted for the current card-to-card payment; its review window or payment state may have changed.\n\nNo payment confirmation or settlement was created by this attempt. Send /menu to view the current state.",
];
