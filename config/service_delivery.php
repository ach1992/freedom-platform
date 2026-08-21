<?php

declare(strict_types=1);

return [
    'presentation' => [
        'mode' => env('SERVICE_DELIVERY_PRESENTATION_MODE', 'text'),
        'inline_link_threshold' => env('SERVICE_DELIVERY_INLINE_LINK_THRESHOLD', 4),
        'qr_source_index' => env('SERVICE_DELIVERY_QR_SOURCE_INDEX', 0),
        'max_document_bytes' => env('SERVICE_DELIVERY_MAX_DOCUMENT_BYTES', 1_048_576),
    ],
];
