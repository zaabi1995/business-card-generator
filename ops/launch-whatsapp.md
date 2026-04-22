# Cardify v2.0 customer-launch WhatsApp — draft for Ali

Per memory:
  - `NEVER send emails without showing draft first` (applies to WA too).
  - `MHD-group excluded from ALL automated outreach`.
  - `OTP / messaging tests → only Ali's number (+96871616161) or own
    emails` — first send of any broadcast goes to Ali only.

WhatsApp is more intimate than email; keep the copy tighter and
personal. One message per recipient, localised, sent from the Anna
line (+96899899100) via the Dardasha API that `includes/WhatsApp.php`
already wraps.

## Segmentation

Send list is `companies.phone` WHERE:
  - `status = 'active'`
  - `phone` is not NULL, matches E.164, starts with `+968` (Oman)
    — non-Oman numbers on WhatsApp have delivery risk; email them
    instead via action 504.
  - `phone_backfill_skips < 3` (avoids numbers the admin refused to
    provide three times).
  - company slug NOT in the MHD exclusion set (11 slugs, see
    `ops/launch-email.md`).

Segmentation query output goes into `ops/launch-whatsapp-list.csv`
only after Ali approves. Columns: company_name, phone, locale,
slug, admin_email (for fallback).

## EN message (≤ 3 short paragraphs)

```
Hi {first_name}, Ali here from Cardify 👋

Quick heads-up — Cardify v2.0 is live. Your account is on it already.
The big wins for you:
• Every card is now fully bilingual Arabic + English.
• Onboarding takes 5 minutes instead of half a day.
• Your receipts show the ERP invoice number next to the Cardify
  order number so your finance team can reconcile without leaving
  the tool.

Nothing you need to do. Log in any time at cardify.om.

If anything looks off, reply here and a real person answers.
```

## AR message

```
مرحباً {first_name}، علي من كارديفاي 👋

خبر سريع — كارديفاي v2.0 صار مباشراً، وحسابك عليه أصلاً. أبرز الفوائد
لك:
• كل البطاقات الآن ثنائية اللغة عربي + إنجليزي بالكامل.
• الإعداد يستغرق 5 دقائق بدلاً من نصف يوم.
• إيصالاتك الآن تعرض رقم فاتورة ERP إلى جانب رقم طلب كارديفاي، ليسهل
  على المحاسبة لديك المطابقة دون مغادرة الأداة.

لا حاجة لأي إجراء منك. سجّل الدخول متى شئت على cardify.om.

إذا لاحظتَ شيئاً غير طبيعي، ردّ هنا ويجيبك شخص حقيقي.
```

## Send checklist

- [ ] Ali reviews both EN + AR bodies.
- [ ] DBA runs segmentation SQL → `ops/launch-whatsapp-list.csv`.
- [ ] Spot-check 3 rows: one OM AR company, one OM EN company, one
      that's the Ali-sanity row (+96871616161).
- [ ] First send goes to Ali ONLY (+96871616161), both EN + AR,
      hold + confirm delivery + rendering on his phone.
- [ ] After Ali's "send it", loop the CSV via
      `WhatsApp::sendMessage($phone, $message)` at 1/s to respect
      the Dardasha rate limit. ~30/minute safe ceiling.
- [ ] Log each send in `data/launch-whatsapp-sent.csv` (company_id,
      phone, locale, sent_at, dardasha_message_id).

## Do NOT

- Auto-send from any cron or scheduled task.
- Send to any number not in `launch-whatsapp-list.csv`.
- Send to MHD Group numbers under any circumstance.
- Message Ali's number repeatedly during the broadcast (one test
  message per locale is enough).

Action 505 stays `[~]` until Ali's explicit "send the v2.0 WhatsApp"
instruction is in the loop.
