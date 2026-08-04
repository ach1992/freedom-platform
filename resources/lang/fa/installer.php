<?php

declare(strict_types=1);

return [
    'title' => 'نصب‌کننده امن',
    'token' => 'توکن یک‌بارمصرف نصب',
    'unlock' => 'بازکردن نصب‌کننده',
    'invalid_token' => 'توکن نصب نامعتبر، منقضی یا قبلاً مصرف شده است.',
    'invalid_environment' => 'محیط نصب شامل کلید یا مقدار پشتیبانی‌نشده است.',
    'preflight' => 'بررسی اولیه محیط',
    'passed' => 'تأیید شد',
    'failed' => 'ناموفق',
    'enabled' => 'فعال',
    'disabled' => 'غیرفعال',
    'none' => 'هیچ‌کدام',
    'not_available' => 'در دسترس نیست',
    'php_runtimes' => 'محیط‌های اجرای PHP',
    'binary' => 'فایل اجرایی',
    'version' => 'نسخه',
    'sapi' => 'SAPI',
    'ini_file' => 'فایل php.ini بارگذاری‌شده',
    'timezone' => 'منطقه زمانی',
    'missing_extensions' => 'افزونه‌های الزامی موجود نیستند',
    'disabled_functions' => 'توابع غیرفعال‌شده',
    'opcache' => 'OPcache',
    'runtime_status' => 'وضعیت محیط اجرا',
    'error_code' => 'کد خطا',
    'checks' => [
        'php_runtimes' => 'PHP خط فرمان و LSPHP',
        'https' => 'HTTPS',
        'database' => 'اتصال پایگاه داده',
        'redis' => 'اتصال Redis احراز هویت‌شده',
        'outbound_https' => 'اتصال خروجی HTTPS به مقصدهای مجاز',
        'disk_space' => 'حداقل فضای آزاد دیسک',
        'storage' => 'قابلیت خواندن و نوشتن مسیرهای اجرایی',
        'ownership' => 'مالکیت مسیرهای اجرایی',
        'utc' => 'منطقه زمانی UTC برنامه',
    ],
    'runtime' => [
        'cli' => 'PHP خط فرمان',
        'lsphp' => 'LSPHP مربوط به OpenLiteSpeed',
    ],
];
