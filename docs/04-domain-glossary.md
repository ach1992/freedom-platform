# Domain Glossary

English identifiers are canonical in code/schema/API. Persian below is the default product vocabulary. User-facing text should use translation keys rather than hard-coded strings.

| Term | Meaning | Persian default |
|---|---|---|
| Customer | account buying for itself | مشتری |
| Agent / Reseller | approved account using agent pricing | نماینده |
| Administrator | privileged management identity | مدیر |
| Owner | highest-trust administrator | مالک سامانه |
| Customer Tier | rule-derived commercial tier | سطح مشتری |
| Tag | manual policy/segmentation label | برچسب |
| Identity Item | phone/card/national-ID/name proof | مدرک هویتی |
| Product / Plan | commercial plan identity | پلن |
| Plan Offering | concrete sellable plan/server terms | عرضه پلن |
| Sales Server | customer-visible server/location choice | سرور |
| Panel Connection | credentialed external panel endpoint | اتصال پنل |
| Service Target | panel host/template/group target | مقصد سرویس |
| Trial | explicit zero-cost service source | سرویس آزمایشی |
| Quote | immutable time-limited pricing/configuration proposal | پیش‌فاکتور |
| Order | commercial commitment; not proof of payment | سفارش |
| Payment Method | method eligible for a payment attempt | روش پرداخت |
| Payment Intent | controlled attempt to settle a payment purpose | درخواست پرداخت |
| Authoritative Capture | trusted final payment settlement | تأیید نهایی پرداخت |
| Manual Review | authorized disposition of ambiguous evidence | بررسی دستی |
| Receipt Evidence | private transfer evidence; not payment authority | رسید پرداخت |
| Gift Card | third-party payment instrument | گیفت‌کارت |
| Gift / Benefit Code | platform-issued benefit code | کد هدیه |
| USDT BEP20 | direct USDT on BNB Smart Chain/BEP20 | تتر شبکه BEP20 |
| Wallet | balances backed by immutable ledger | کیف پول |
| Cash Balance | transferable captured-value bucket | موجودی نقدی |
| Promotional Credit | policy-limited promotional bucket | اعتبار هدیه |
| Ledger | append-only balanced accounting journal | دفتر کل |
| Hold | atomic reservation reducing available value | رزرو موجودی |
| Refund | return of refundable captured value | بازپرداخت |
| Reconciliation | compare local and authoritative external/accounting state | تطبیق |
| Operations Center | privileged operational control surface | مرکز عملیات |
| Audit Log | append-only privileged activity record | گزارش حسابرسی |
| Backup | encrypted restorable snapshot + manifest | نسخه پشتیبان |
| Release | immutable application version/package | نسخه انتشار |
| Rollback | return to a compatible prior release/state | بازگشت نسخه |

## Formatting rules

- Store fiat as integer `IRR`; customer display must explicitly label the converted Toman amount.
- Store timestamps in UTC; display business time in `Asia/Tehran` where required.
- Mask sensitive phone/card/identity/wallet/code/subscription values by default.
- Do not label a payment «موفق» before authoritative capture.
- Keep storage enums/status identifiers in English and localize only presentation.