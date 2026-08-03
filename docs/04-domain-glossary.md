# Domain Glossary and Persian Terminology

Document status: `planned`  
Implementation status: `not-started`

English terms are canonical in code, schema, logs, APIs, and developer documentation. Persian terms below are the default product vocabulary. Translation keys—not hard-coded strings—remain the source of visible copy.

## Commercial and identity terms

| Canonical term | Meaning | Persian UI default | Usage note |
|---|---|---|---|
| User | Internal person/account record | کاربر | Do not equate with Telegram chat. |
| Telegram Account | Telegram identity bound by immutable Telegram user ID | حساب تلگرام | A user may also be an administrator. |
| Customer | Commercial account buying for self | مشتری | Internal account type `customer`. |
| Agent / Reseller | Approved commercial account using agent pricing | نماینده | Use «درخواست همکاری» for application; avoid mixing «همکار» as a separate type. |
| Administrator | Telegram identity with administrative authorization | مدیر | Separate from customer/agent classification. |
| Owner / Super Admin | Singular highest-trust administrator | مالک سامانه | Use «مالک» for sensitive confirmations. |
| Account Status | Active/limited/suspended/blocked commercial state | وضعیت حساب | Defaults: فعال، محدود، تعلیق‌شده، مسدود. |
| Customer Tier | Rule-derived commercial tier | سطح مشتری | Values: جدید، عادی، وفادار، ویژه. |
| Tag | Manual policy/segmentation label | برچسب | Not a tier or permission. |
| Identity Item | Phone, card, national ID, or name proof | مدرک هویتی | States: تأییدنشده، در انتظار بررسی، تأییدشده، ردشده. |
| Cooperation Request | Agent application | درخواست همکاری | States exposed as localized content. |
| Pricing Profile | Agent-specific commercial rules | پروفایل قیمت‌گذاری | Applied and snapshotted at quote time. |

## Catalog and service terms

| Canonical term | Meaning | Persian UI default | Usage note |
|---|---|---|---|
| Category | Display grouping | دسته‌بندی | Archiving preserves historical references. |
| Product / Plan | Commercial identity independent of server | پلن | Prefer «پلن» consistently in compact Telegram UI. |
| Plan Offering | A plan sold on a particular sales server with concrete terms | عرضه پلن | Customer copy normally shows plan + server, not this technical term. |
| Sales Server | Customer-visible location/server choice | سرور | May be manually or automatically selected. |
| Panel Connection | Credentialed external panel endpoint | اتصال پنل | Admin-only term. |
| Service Target | Inbound/group/template/host within a panel | مقصد سرویس | Admin-only; never expose raw panel objects. |
| Service Mode | Typed shared/dedicated/future mode | نوع سرویس | Defaults: اشتراکی، اختصاصی. |
| Protocol Profile | Validated transport/protocol configuration | پروفایل اتصال | Show only when selection is supported. |
| Capability | Adapter-supported operation | قابلیت | Both adapter and offering policy must permit action. |
| Capacity | Available sales/provisioning capacity | ظرفیت | Insufficient capacity can stop sale or use disclosed fallback. |
| Fallback Server | Pre-approved compatible alternate target | سرور جایگزین | Must be disclosed before payment if user chose a server. |
| Service Subscription | Local owned service linked to remote identity | سرویس | Avoid calling it an order. |
| Subscription Link | Sensitive client subscription URL/token | لینک اشتراک | Never log; use «تعویض لینک» for rotation. |
| Configuration Link | Individual client configuration | لینک کانفیگ | Use selected positions per delivery policy. |
| Trial | Zero-cost service order source | سرویس آزمایشی | Never model as successful payment. |
| Import Existing Service | Attach verified remote service without payment history | افزودن سرویس موجود | Customer navigation may use «پیدا کردن سرویس موجود». |
| Retire Service | Preserve history while ending/removing service | بازنشسته‌کردن سرویس | Customer-facing destructive action may be labelled «حذف سرویس» with warning. |
| Reconciliation / Repair | Compare local and authoritative remote state and correct allowed metadata | تطبیق و اصلاح | Never rewrites financial history. |

## Order and payment terms

| Canonical term | Meaning | Persian UI default | Usage note |
|---|---|---|---|
| Quote | Time-limited immutable pricing/configuration proposal | پیش‌فاکتور | Includes explicit currency and expiry. |
| Price Snapshot | Immutable calculation components | جزئیات قیمت | Preserves historical calculation. |
| Order | Commercial commitment containing one or more items | سفارش | Not proof of payment. |
| Order Item | Independently provisionable line | آیتم سفارش | Each has its own provisioning idempotency key. |
| Payment Method / Gateway | Complete method selected for an intent | روش پرداخت | «درگاه» may be used for IPG only. |
| Payment Intent | One controlled attempt to settle an order by one method | درخواست پرداخت | Usually hidden as a technical term in customer copy. |
| Payment Attempt | A provider interaction within an intent | تلاش پرداخت | Operational term. |
| Provider Transaction | Normalized authoritative external transaction | تراکنش ارائه‌دهنده | A Telegram message/screenshot is not one. |
| Authoritative Capture | Final trusted settlement event | تأیید نهایی پرداخت | Only this permits paid provisioning. |
| Manual Review | Authorized human disposition of ambiguous evidence | بررسی دستی | Approval/rejection always requires reason where specified. |
| Exact Amount | Base plus unique upward card adjustment | مبلغ دقیق واریز | Show exact Toman value; never round. |
| Exact Adjustment | 100–999 Toman uniqueness increment | مبلغ افزوده تطبیق | Part of paid amount; non-refundable. |
| Receipt Evidence | Private submitted transfer evidence | رسید پرداخت | Not authoritative by itself. |
| Gift Card | External code/image payment instrument | گیفت‌کارت | Keep separate from Gift Code. |
| Gift Code | Platform-issued credit/service/discount code | کد هدیه | Never call a third-party gift card a gift code. |
| USDT BEP20 | Direct stablecoin payment on Binance Smart Chain | تتر شبکه BEP20 | Network must always be visible. |
| TXID | Blockchain transaction identifier | شناسه تراکنش (TXID) | Unique and verified against network/destination. |
| Refund | Full or partial return of refundable captured value | بازپرداخت | Exact card adjustment is excluded. |
| Reversal | Provider/accounting undo after earlier success | برگشت تراکنش | May trigger a Critical incident. |

## Wallet and accounting terms

| Canonical term | Meaning | Persian UI default | Usage note |
|---|---|---|---|
| Wallet | Customer balances backed by ledger | کیف پول | Not a mutable balance row. |
| Cash Balance | Transferable captured-value bucket by default | موجودی نقدی | Subject to policy. |
| Promotional Credit | Normally non-transferable expiring bucket | اعتبار هدیه | Consume expiring credit first when eligible. |
| Ledger | Immutable balanced accounting journal | دفتر کل | Source of financial truth. |
| Ledger Transaction | Balanced group of entries | سند مالی | Append-only. |
| Ledger Entry | Debit or credit posting | ثبت بدهکار/بستانکار | Developer/accounting context. |
| Hold | Atomic reservation reducing available balance | رزرو موجودی | Captured or released once. |
| Capture | Finalize a hold/payment | برداشت نهایی | Do not translate as receipt approval. |
| Release | Remove an unused hold/reservation | آزادسازی | Must be idempotent. |
| Balance Correction | Audited compensating admin transaction | اصلاح موجودی | Never edit prior entry. |

## Operations, messaging, and support terms

| Canonical term | Meaning | Persian UI default | Usage note |
|---|---|---|---|
| Ticket | Customer support case with tracking number | تیکت پشتیبانی | States: جدید، منتظر پشتیبانی، منتظر مشتری، در حال بررسی، حل‌شده، بسته. |
| Internal Note | Staff-only ticket content | یادداشت داخلی | Must never be delivered to customer. |
| Broadcast Campaign | Managed mass-message lifecycle | ارسال همگانی | Store per-recipient state. |
| Direct Message | Admin-to-one-customer outbound message | پیام مستقیم | Confirmation shows target Telegram ID. |
| Operations Center | Telegram operational control plane | مرکز عملیات | Permission-filtered safe actions. |
| Alert | Durable operational/security notification | هشدار | Levels: اطلاع، هشدار، بحرانی، امنیتی. |
| Audit Log | Append-only actor/action/before-after record | گزارش حسابرسی | Normal admins cannot alter/delete. |
| Correlation ID | Safe tracking identifier across work | شناسه پیگیری | Include in incidents; contains no secret. |
| Reconciliation | Compare internal and authoritative external/accounting state | تطبیق | Differences become cases/incidents. |
| Idempotency Key | Stable key preventing repeated effect | کلید یکتایی عملیات | Technical term; do not expose normally. |
| Transactional Outbox | Committed queue of external effects | صندوق خروجی تراکنشی | Technical term. |
| Dead Letter | Exhausted job awaiting intervention | صف بررسی دستی | Prefer user-facing meaning over queue jargon. |
| Backup | Encrypted restorable snapshot and manifest | نسخه پشتیبان | Success requires all mandatory parts acknowledged. |
| Restore | Controlled recovery from verified backup | بازیابی | Requires authorization, safety backup, and verification. |
| Release | Versioned immutable application package | نسخه انتشار | Distinct from wallet hold release. |
| Rollback | Return to compatible prior code/schema state | بازگشت نسخه | Database restore may be required. |

## Language and formatting rules

- UI source strings use Persian defaults, natural Persian sentence order, and Unicode-safe formatting.
- Developer identifiers remain English: enums such as `pending_manual_review` are never localized in storage.
- Display fiat as an integer Toman amount with thousands separators and the explicit label `تومان`; store and compute integer `IRR`.
- Display all business dates in `Asia/Tehran`; optionally show Jalali date, while logs/evidence retain unambiguous UTC timestamps.
- Mask sensitive values consistently: phone, PAN/IBAN, national ID, gift-card code, wallet address, and subscription links.
- Prefer «بازگشت» for Back, «انصراف» for Cancel, «تأیید» for Confirm, and «تلاش مجدد» for Retry.
- Do not use «موفق» for a payment until authoritative capture; use «در انتظار تأیید» for submitted/manual-review states.
