<?php

declare(strict_types=1);

return [
    'entry_button' => 'مرکز عملیات',
    'menu' => "مرکز عملیات\n\nوضعیت فعلی فقط از authorityهای موجود هر دامنه خوانده می‌شود. «نامشخص» یعنی شواهد authoritative کافی برای اعلام سلامت وجود ندارد.",
    'snapshot_button' => 'وضعیت فعلی',
    'alerts_button' => 'هشدارهای عملیاتی',
    'dispatch_outbox_button' => 'اجرای کارهای due در Outbox',
    'refresh_button' => 'به‌روزرسانی',
    'snapshot_title' => 'نمای لحظه‌ای مرکز عملیات',
    'alerts_title' => 'هشدارهای عملیاتی حل‌نشده',
    'alerts_empty' => 'در حال حاضر هشدار عملیاتی حل‌نشده‌ای ثبت نشده است.',
    'alert_title' => 'هشدار عملیاتی',
    'ack_button' => 'تأیید مشاهده هشدار',
    'resolve_button' => 'حل هشدار',
    'acknowledged' => 'هشدار با مجوز فعلی acknowledge شد.',
    'resolved' => 'هشدار با مجوز فعلی resolve شد.',
    'dispatch_completed' => 'یک نوبت محدود از dispatcher اصلی Outbox اجرا شد. موارد review_required و failed-job دوباره اجرا نشدند.',
    'operation_unavailable' => 'عملیات در دسترس نبود یا به‌صورت fail-closed متوقف شد. هیچ retry/replay عمومی انجام نشد.',
];
