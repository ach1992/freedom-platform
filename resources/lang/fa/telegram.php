<?php

declare(strict_types=1);

return [
    'navigation' => [
        'home' => "به Freedom Platform خوش آمدید.\n\nمنوی اصلی\nیکی از گزینه‌های زیر را انتخاب کنید.\n/cancel — بستن نشست فعلی",
        'buttons' => [
            'my_account' => 'حساب من',
            'my_services' => 'سرویس‌های من',
            'back' => 'بازگشت',
        ],
        'account' => [
            'view' => "حساب من\n\nشناسه حساب: :public_id\nنوع حساب: :account_type\nوضعیت: :account_status\nسطح: :tier\nتأیید تلفن: :phone_verification\nتأیید هویت: :identity_verification\nاطلاعات هویتی:\n:identity_items\nتاریخ عضویت: :joined_at\nآخرین مشاهده: :last_seen_at\n\nکیف پول\nموجودی قابل استفاده نقدی: :cash_available ریال\nمبلغ در حال نگهداری: :cash_holds ریال\nموجودی تشویقی قابل استفاده: :promotional_available ریال\n\nمعرفی\nکد معرفی شما: :referral_token\nمعرف ثبت شده: :has_inviter\nوضعیت قفل معرفی: :referral_locked",
            'identity_item' => '• :type — :masked (:state)',
            'identity_none' => '• موردی ثبت نشده است',
            'not_available' => 'ثبت نشده',
            'yes' => 'بله',
            'no' => 'خیر',
            'values' => [
                'account_type' => ['customer' => 'مشتری', 'agent' => 'نماینده'],
                'account_status' => [
                    'active' => 'فعال', 'limited' => 'محدود', 'suspended' => 'تعلیق‌شده', 'blocked' => 'مسدود',
                ],
                'tier' => ['new' => 'جدید', 'normal' => 'عادی', 'loyal' => 'وفادار', 'vip' => 'ویژه (VIP)'],
                'verification' => [
                    'unverified' => 'تأییدنشده', 'pending' => 'در انتظار', 'verified' => 'تأییدشده', 'rejected' => 'ردشده',
                ],
                'identity_type' => [
                    'national_id' => 'کد ملی', 'bank_card' => 'کارت بانکی', 'full_name' => 'نام و نام خانوادگی',
                ],
            ],
        ],
        'services' => [
            'list' => "سرویس‌های من\n\n:items\n\nصفحه :page از :total_pages — :total_items سرویس",
            'list_item' => "#:number — :public_id\n:plan · :server\nوضعیت: :state",
            'empty' => "سرویس‌های من\n\nهنوز سرویسی برای شما ثبت نشده است.",
            'service_button' => 'سرویس #:number',
            'previous' => 'قبلی',
            'next' => 'بعدی',
            'not_available' => 'ثبت نشده',
            'search' => [
                'button' => 'جستجو',
                'prompt' => "جستجوی سرویس‌های من\n\nشناسه دقیق سرویس، شناسه سفارش یا نام کاربری سرویس را ارسال کنید.\nجستجو خصوصی و دقیق است.",
                'not_found' => "سرویس منطبقی در حساب شما پیدا نشد.\n\nشناسه دقیق سرویس، شناسه سفارش یا نام کاربری سرویس را دوباره بررسی کنید.",
                'ambiguous' => "بیش از یک سرویس شما این نام کاربری را دارد.\n\nبرای انتخاب امن، با شناسه دقیق سرویس یا شناسه سفارش جستجو کنید.",
            ],
            'detail' => "جزئیات سرویس\n\nشناسه سرویس: :public_id\nپلن: :plan\nسرور: :server\nوضعیت محلی: :state\nزمان ایجاد سرویس: :provisioned_at\n\nهمگام‌سازی\nوضعیت شواهد: :sync_state\nوضعیت مشاهده: :remote_disposition\nوضعیت سرویس: :remote_status\nحجم کل: :data_limit\nمصرف‌شده: :used\nباقی‌مانده: :remaining\nانقضا: :expires_at\nآخرین مشاهده: :observed_at",
            'values' => [
                'lifecycle' => [
                    'active' => 'فعال', 'suspended' => 'تعلیق‌شده', 'retired' => 'بازنشسته',
                ],
                'sync_state' => [
                    'none' => 'هنوز همگام‌سازی ثبت نشده',
                    'current' => 'به‌روز',
                    'cached' => 'ذخیره‌شده — همگام‌سازی فعلی موقتاً در دسترس نیست؛ آخرین اطلاعات تأییدشده نمایش داده می‌شود',
                    'stale' => 'قدیمی — اطلاعات راه‌دور نمایش داده نمی‌شود',
                ],
                'remote_disposition' => [
                    'present' => 'موجود',
                    'missing' => 'یافت نشد',
                    'unavailable' => 'موقتاً در دسترس نیست',
                    'identity_mismatch' => 'ناهماهنگی هویت سرویس',
                ],
                'remote_status' => [
                    'active' => 'فعال', 'suspended' => 'تعلیق‌شده', 'expired' => 'منقضی', 'disabled' => 'غیرفعال', 'unknown' => 'نامشخص',
                ],
            ],
        ],
    ],
];
