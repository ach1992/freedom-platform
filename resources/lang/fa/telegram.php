<?php

declare(strict_types=1);

return [
    'navigation' => [
        'home' => "به Freedom Platform خوش آمدید.\n\nمنوی اصلی\nیکی از گزینه‌های زیر را انتخاب کنید.\n/cancel — بستن نشست فعلی",
        'buttons' => [
            'my_account' => 'حساب من',
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
                'account_type' => [
                    'customer' => 'مشتری',
                    'agent' => 'نماینده',
                ],
                'account_status' => [
                    'active' => 'فعال',
                    'limited' => 'محدود',
                    'suspended' => 'تعلیق‌شده',
                    'blocked' => 'مسدود',
                ],
                'tier' => [
                    'new' => 'جدید',
                    'normal' => 'عادی',
                    'loyal' => 'وفادار',
                    'vip' => 'ویژه (VIP)',
                ],
                'verification' => [
                    'unverified' => 'تأییدنشده',
                    'pending' => 'در انتظار',
                    'verified' => 'تأییدشده',
                    'rejected' => 'ردشده',
                ],
                'identity_type' => [
                    'national_id' => 'کد ملی',
                    'bank_card' => 'کارت بانکی',
                    'full_name' => 'نام و نام خانوادگی',
                ],
            ],
        ],
    ],
];
