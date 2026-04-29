# Cardify v2.0 customer-launch email — draft for Ali

Per memory `NEVER send emails without showing draft first` + `MHD-
group excluded from ALL automated outreach`. Two drafts (EN + AR) +
segmentation + send list + checklist. Nothing goes out until Ali
explicitly approves it.

## Segmentation

The send list is `companies.admin_email` WHERE:
  - `status = 'active'`
  - `admin_email` is not NULL and passes `filter_var(..., EMAIL)`
  - company slug is NOT in the MHD exclusion set:
    `mhd, mhd-itics, mhd-automotive, mhd-healthcare, mhd-tech-comm,
    mhd-consumer, mhd-building-materials, mhd-infrastructure,
    mhd-logistics, mhd-office-products`
  - NOT the test Click-Guincho row (auto-generated during QA).

Generated once, saved to `ops/launch-email-list.csv` only after Ali
approves the segmentation (columns: company_name, admin_email,
locale, slug). Locale derived from `companies.country` (OM default
ar, rest en).

## EN body

**Subject:** Cardify v2.0 — bilingual cards for every employee

**Body (HTML + text sibling):**

```
Hi {first_name},

Quick note — Cardify v2.0 shipped this week, and your account is
already on it. Here's what changed for you.

Your cards are now fully bilingual.
  - Every employee card renders correctly in Arabic with proper RTL,
    alongside the English version.
  - A one-click language switcher on the admin sidebar.

Onboarding is 5 minutes, not an afternoon.
  - New /admin/onboarding wizard seeds a first card so you see the
    output before inviting the team.

Your invoices now show the ERP invoice number.
  - Every receipt carries both the Cardify order number AND the
    BHD-ERP invoice number, so your finance team can reconcile
    without leaving Cardify.
  - 5% Oman VAT breakdown on every invoice, with CR number + Tax ID
    if you've filled them in under /admin/billing-info.

Saved cards for repeat payments.
  - Card details stay securely with Paymob (PCI scope stays there
    too); next time you renew or top up, it's one click.

Better public card pages.
  - New Omani Business Index live: /oman-business-index (free).
  - New case studies + pricing + status + changelog pages, all
    bilingual.

Nothing you need to do. Your existing company + employees + templates
keep working exactly as they were.

One favour: if anything looks off, hit reply or WhatsApp us on
+968 9889 9100 — a real person answers within office hours.

Ali Al-Zaabi
Group CEO, BHD Printing & Designing
cardify.om
```

## AR body

**Subject:** كارديفاي v2.0 — بطاقات ثنائية اللغة لكل موظف في فريقك

**Body:**

```
مرحباً {first_name}،

رسالة سريعة — شحنّا كارديفاي v2.0 هذا الأسبوع، وحسابك عليه أصلاً.
إليك ما الجديد بالنسبة لك.

بطاقاتك ثنائية اللغة بالكامل.
  - كل بطاقة موظف تُعرض بالعربية بشكل صحيح مع اتجاه RTL، إلى جانب
    النسخة الإنجليزية.
  - زر تبديل لغة بنقرة واحدة في الشريط الجانبي للإدارة.

الإعداد يستغرق 5 دقائق، لا نصف يوم.
  - معالج جديد في /admin/onboarding يُنشئ أول بطاقة لترى النتيجة
    قبل دعوة الفريق.

فواتيرك الآن تعرض رقم فاتورة ERP.
  - كل إيصال يحمل رقم طلب كارديفاي ورقم فاتورة BHD-ERP معاً، لتسهل
    المطابقة على المحاسبة دون مغادرة كارديفاي.
  - فصل ضريبة القيمة المضافة العُمانية (5%) في كل فاتورة، مع رقم
    السجل التجاري والرقم الضريبي إن حدّثتَهما في /admin/billing-info.

بطاقات دفع محفوظة لإعادة الشراء.
  - تبقى تفاصيل البطاقة بأمان لدى Paymob (معايير PCI تبقى عندهم)،
    وفي مرة التجديد أو الشحن التالية، نقرة واحدة تكفي.

صفحات عامة محدّثة.
  - دليل الأعمال العُماني الجديد متاح: /oman-business-index (مجاني).
  - صفحات دراسات حالة + الأسعار + الحالة + سجل التغييرات جديدة،
    كلها ثنائية اللغة.

لا حاجة لأي إجراء منك. شركتك وموظفوك وقوالبك تواصل العمل كما هي.

طلب واحد: إذا لاحظتَ شيئاً غير طبيعي، ردّ على هذه الرسالة أو راسلنا
على واتساب 96899899100 — يجيبك شخص حقيقي في ساعات العمل.

علي الزعابي
الرئيس التنفيذي للمجموعة، BHD للطباعة والتصميم
cardify.om
```

## Send checklist

- [ ] Ali reviews both EN + AR bodies.
- [ ] DBA runs the segmentation SQL + saves list to
      `ops/launch-email-list.csv` (MHD excluded).
- [ ] Spot-check 3 rows: one OM company (AR), one non-OM (EN), one
      sanity row (Ali's own admin_email).
- [ ] Send from info@cardify.om via `Mailer::sendTemplated()` with
      the recipient's locale.
- [ ] First batch: send to Ali only (ali@bhd.om) as a final eyes-on
      check.
- [ ] After Ali's "send it", batch of 20/hour to respect Hostinger
      SMTP throttling.
- [ ] Log each send in `data/launch-email-sent.csv` (company_id,
      email, locale, sent_at, mailer_message_id).

**Do NOT run the automated send without Ali's explicit
"send the v2.0 email" instruction.**
