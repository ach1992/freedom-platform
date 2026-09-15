<?php

declare(strict_types=1);

return [
    'open_gateway' => 'Open Zarinpal',
    'redirect' => "Zarinpal checkout is ready\n\nOpen the secure Zarinpal payment page using the button below.\n\nCreating or opening this redirect does not confirm payment. The Order still requires authoritative server-side verification and capture before it is paid, and Service provisioning has not started.",
    'unavailable' => "Zarinpal checkout is not available for this Order right now.\n\nThis screen does not claim a successful payment, settlement, or Service provisioning. You can return to the current payment methods when the Order is still payable.",
    'uncertain' => "The Zarinpal request outcome is uncertain after communication with the payment provider.\n\nDo not start another Zarinpal request from this screen. The existing request must be reconciled before any provider retry. No successful payment is being claimed and Service provisioning has not started.",
];
