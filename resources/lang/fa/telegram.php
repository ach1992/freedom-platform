<?php

declare(strict_types=1);

return [
    'navigation' => [
        'home' => "به Freedom Platform خوش آمدید.\n\nمنوی اصلی\nیکی از گزینه‌های زیر را انتخاب کنید.\n/cancel — بستن نشست فعلی",
        'buttons' => [
            'my_account' => 'حساب من',
            'buy_service' => 'خرید سرویس',
            'my_services' => 'سرویس‌های من',
            'admin' => 'مدیریت',
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
        'admin' => [
            'control' => "مرکز مدیریت\n\nتنظیمات و ابزارهای مدیریتی فقط براساس مجوز فعلی شما نمایش داده می‌شوند و هنگام اجرا دوباره بررسی می‌شوند.",
            'buttons' => [
                'usdt_rate' => 'نرخ USDT / NOWPayments',
            ],
            'usdt_rate' => [
                'view' => "نرخ دستی USDT\n\nنرخ فعلی: :rate ریال برای هر USDT\nمنبع: :source\nنسخه مدیریت‌شده: :version\n\nاین نرخ مشترک برای پرداخت مستقیم USDT و طبق سیاست فعلی مالک، به‌عنوان نرخ مبنای قیمت‌گذاری USD در NOWPayments استفاده می‌شود.",
                'unset' => "نرخ دستی USDT\n\nدر حال حاضر نرخ مدیریت‌شده یا مقدار پیش‌فرض راه‌اندازی در دسترس نیست.\n\nاین تنظیم، نرخ مشترک پرداخت مستقیم USDT و مبنای قیمت‌گذاری USD در NOWPayments است.",
                'edit_button' => 'تغییر نرخ',
                'prompt' => "تغییر نرخ دستی USDT\n\nنرخ جدید IRR برای هر USDT را فقط به‌صورت عدد ارسال کنید.\nمثال: 900000\n\nاعداد فارسی و عربی نیز پذیرفته می‌شوند.",
                'confirm' => "تأیید تغییر نرخ دستی USDT\n\nنرخ جدید: :rate ریال برای هر USDT\n\nاین نرخ بر قیمت‌گذاری‌های بعدیِ پرداخت مستقیم USDT و پروکسی USD در NOWPayments اثر می‌گذارد. پرداخت‌ها و اسنپ‌شات‌های قبلی بازنویسی نمی‌شوند.\n\nتا زمانی که دکمه «تأیید تغییر نرخ» را نزنید، هیچ تغییری ثبت نمی‌شود.",
                'confirm_button' => 'تأیید تغییر نرخ',
                'invalid' => "مقدار نرخ معتبر نیست یا خارج از محدوده مجاز تنظیمات است.\n\nیک عدد معتبر IRR برای هر USDT ارسال کنید.",
                'updated_notice' => 'نرخ مدیریت‌شده با موفقیت ثبت شد.',
                'not_managed' => 'ندارد',
                'sources' => [
                    'managed' => 'مدیریت‌شده',
                    'bootstrap' => 'پیش‌فرض راه‌اندازی',
                ],
            ],
        ],
        'purchase' => [
            'list' => 'خرید سرویس

:items

صفحه :page از :total_pages — :total_items گزینه در دسترس',
            'list_item' => '#:number — :plan
دسته‌بندی: :category
نوع سرویس: :mode
قیمت پایه: :price ریال
مدت: :duration روز',
            'empty' => 'خرید سرویس

در حال حاضر گزینه واجد شرایطی برای حساب شما در دسترس نیست.',
            'offering_button' => 'گزینه #:number',
            'previous' => 'قبلی',
            'next' => 'بعدی',
            'not_available' => 'ثبت نشده',
            'detail' => 'گزینه سرویس

دسته‌بندی: :category
پلن: :plan
نوع سرویس: :mode
قیمت پایه: :price ریال
مدت: :duration روز
حجم: :data
تعداد دستگاه: :devices

این مرحله فقط مرور کاتالوگ است. هنوز هیچ پیش‌فاکتور، پرداخت، رزرو ظرفیت، سفارش یا پروویژنینگی ایجاد نشده است.',
            'quote_button' => 'مشاهده پیش‌فاکتور',
            'quote' => 'پیش‌فاکتور خرید سرویس

شناسه پیش‌فاکتور: :quote_id
پلن: :plan
قیمت پایه: :base_price :currency
قیمت مؤثر: :effective_price :currency
تخفیف: :discount :currency
مبلغ نهایی: :final_price :currency
اعتبار تا: :expires_at (زمان تهران)

این پیش‌فاکتور فقط یک snapshot تجاری ثبت‌شده است. هنوز هیچ پرداخت، رزرو ظرفیت، سفارش یا پروویژنینگی ایجاد نشده است.',
            'discount' => [
                'button' => 'اعمال کد تخفیف',
                'prompt' => 'کد تخفیف را در یک پیام ارسال کنید. کد در وضعیت گفتوگو، callback یا متن پاسخ ذخیره یا بازتاب داده نمیشود. اگر نمیخواهید کد اعمال کنید، بازگشت را بزنید.',
                'rejected' => 'کد تخفیف یا پیشفاکتور فعلی قابل اعمال نیست. هیچ تخفیف، رزرو پرداخت، سفارش یا Payment Intent ثبت نشد. میتوانید کد دیگری ارسال کنید یا بازگردید.',
            ],
            'payment_methods_button' => 'روش‌های پرداخت',
            'payment_methods' => [
                'list' => "روش‌های پرداخت مجاز\n\n:items\n\nاین فهرست از تصمیم فعلی و ثبت‌شده PAY-001 ساخته شده است. هنوز هیچ سفارش، Payment Intent، برداشت کیف پول، درخواست درگاه یا پرداختی ایجاد نشده است.",
                'item' => ':number. :method',
                'empty' => "روش‌های پرداخت\n\nدر حال حاضر هیچ روش پرداخت واجد شرایطی برای این پیش‌فاکتور در دسترس نیست.\n\nهیچ سفارش، Payment Intent یا اثر مالی ایجاد نشده است.",
                'methods' => [
                    'wallet' => 'کیف پول',
                    'card_to_card' => 'کارت‌به‌کارت',
                    'gift_card' => 'گیفت‌کارت',
                    'usdt_bep20' => 'USDT (BEP20)',
                    'zarinpal' => 'زرین‌پال',
                    'nowpayments' => 'NOWPayments',
                    'other' => 'روش پرداخت #:number',
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
            'allowed_actions_none' => 'در حال حاضر عملیاتی در دسترس نیست',
            'allowed_actions_separator' => '، ',
            'resend' => [
                'button' => 'ارسال مجدد امن مشخصات',
                'queued' => "مشخصات امن سرویس برای ارسال از مسیر محافظت‌شده در صف قرار گرفت.\n\nهیچ اطلاعات ورود، لینک یا داده QR در این پیام نمایش داده نمی‌شود.",
                'temporarily_blocked' => "در حال حاضر به‌دلیل وجود یک عملیات یا ارسال در حال انجام، ارسال مجدد امن مشخصات سرویس ممکن نیست.\n\nکمی بعد دوباره تلاش کنید.",
                'unavailable' => 'این سرویس دیگر برای ارسال مجدد امن مشخصات در دسترس نیست.',
            ],
            'search' => [
                'button' => 'جستجو',
                'prompt' => "جستجوی سرویس‌های من\n\nشناسه دقیق سرویس، شناسه سفارش یا نام کاربری سرویس را ارسال کنید.\nجستجو خصوصی و دقیق است.",
                'not_found' => "سرویس منطبقی در حساب شما پیدا نشد.\n\nشناسه دقیق سرویس، شناسه سفارش یا نام کاربری سرویس را دوباره بررسی کنید.",
                'ambiguous' => "بیش از یک سرویس شما این نام کاربری را دارد.\n\nبرای انتخاب امن، با شناسه دقیق سرویس یا شناسه سفارش جستجو کنید.",
            ],
            'detail' => "جزئیات سرویس\n\nشناسه سرویس: :public_id\nپلن: :plan\nسرور: :server\nوضعیت محلی: :state\nزمان ایجاد سرویس: :provisioned_at\nعملیات مجاز: :allowed_actions\n\nهمگام‌سازی\nوضعیت شواهد: :sync_state\nوضعیت مشاهده: :remote_disposition\nوضعیت سرویس: :remote_status\nحجم کل: :data_limit\nمصرف‌شده: :used\nباقی‌مانده: :remaining\nانقضا: :expires_at\nآخرین مشاهده: :observed_at",
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
                'action' => [
                    'renew' => 'تمدید',
                    'add_data' => 'افزایش حجم',
                    'add_days' => 'افزایش زمان',
                    'add_data_days' => 'افزایش حجم و زمان',
                    'reset_usage' => 'بازنشانی مصرف',
                ],
            ],
        ],
    ],
];
