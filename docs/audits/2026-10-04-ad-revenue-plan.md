Cardify advertising review, 4 October 2026

Start with the logo library, directory and editorial pages. Use a measured ad experiment alongside clearly labelled direct sponsorship. The main commercial product pages should keep their focus on Cardify subscriptions and print orders.

Observed state

- The live /logos page had 68 logo cards and no AdSense or DoubleClick scripts on inspection.
- /ads.txt returned a 404. There is no publisher ID or ad-slot configuration in the reviewed repository. AdSense approval and account ownership have not been verified.
- The current Content Security Policy has no allowance for pagead2.googlesyndication.com or DoubleClick ad frames. Adding the ad script alone would be blocked.
- The site already has an extensive directory, logo profiles, free tools and a blog, including a government logo guide. These give us contextual inventory.
- No authenticated GA4 or AdSense revenue data was available for this review. The number of indexed pages is not evidence of monetizable human traffic.

Proposed placements, applied to Arabic and English URLs

| Page family | First experiment | Maximum initial units | Purpose |
| --- | --- | --- | --- |
| /logos and /logos/{sector} | In-feed placement after 8 real logo cards, second after 24 if enough results | 2 | Reach designers and brand researchers |
| /companies/{slug} | Responsive display unit after the factual profile, separated from logo download buttons; second below related companies | 2 | Monetize directory research |
| /companies, sector and governorate hubs | In-feed unit after 10 listings, optional second after 25 | 2 | Directory browsing |
| /oman-business-index and /gcc-business-index | Unit below substantive report content, plus a sponsor position | 2 | Business intelligence audience |
| /blog/{slug} | In-article unit after the third substantive paragraph, second before related articles | 2 | Editorial engagement |
| /blog | In-feed position after the sixth article | 1 | Editorial discovery |
| /tools/{slug} | Below the completed result and explanatory content, separated from inputs and download controls | 1 | Repeated free-tool use |
| Government logo packs | Contextual unit after source and usage details, visually separate from the entity identity | 1 within the profile limit | Avoid suggesting that an entity endorses an advertiser |

Use a route allowlist. Exclude admin, printshop, company portals, employee digital cards, authentication, payment, checkout, upload, error pages, download endpoints and private communications. Leave the home, pricing, onboarding and subscription purchase flows out of the initial test because revenue lost from a distracted customer could exceed ad income.

Monetization model

Use AdSense for unsold inventory. Sell direct sponsorship of a logo-library category, a directory sector or a relevant editorial series to businesses serving that audience. Do not alter factual directory rankings to benefit a sponsor. BHD service promotions can occupy unsold space but are internal marketing, not third-party ad revenue. Direct sponsorship is a hypothesis to test, not an established demand forecast.

Make sponsored inventory visually distinct and labelled. Do not place ad boxes inside the download-button group or create an ad timer before a download. Google prohibits layouts that cause accidental ad clicks, and ads must remain distinguishable from navigation and content. See [Google ad placement policies](https://support.google.com/adsense/answer/1346295?hl=en).

Revenue calculation

Monthly ad revenue = monetized pageviews / 1,000 × realized page RPM.

The following are illustrative scenarios in OMR, not traffic estimates or market RPM benchmarks. Page RPM means revenue per 1,000 pageviews, so do not multiply it again by the number of ad slots.

| Monetized pageviews / month | OMR 0.500 page RPM | OMR 1.500 page RPM | OMR 3.000 page RPM |
| --- | --- | --- | --- |
| 10,000 | 5.000 | 15.000 | 30.000 |
| 50,000 | 25.000 | 75.000 | 150.000 |
| 100,000 | 50.000 | 150.000 | 300.000 |
| 500,000 | 250.000 | 750.000 | 1,500.000 |

Before enabling ads

1. Confirm an approved AdSense account for cardify.om and read its real publisher ID and ad-unit IDs. Add the exact supplied ads.txt entry. Never use sample IDs from documentation.
2. Record 28 days of actual human pageviews by page family, device, language, country and engagement. Separate bots and internal traffic. Record logo downloads, lead unlocks, new accounts, paid subscriptions and print-order gross profit.
3. Review consent requirements for the actual visitor markets and chosen ad setup. Use the required Google-certified CMP where applicable, and align the privacy/cookie disclosures with the installed tracking. See [Google consent management requirements](https://support.google.com/adsense/answer/13554116?hl=en).
4. Build a feature-flagged, localized ad component with exact slot IDs, a route allowlist, one script loader, responsive dimensions and lazy requests. Give ad containers sufficient width and variable height for in-feed formats, per [Google in-feed placement instructions](https://support.google.com/adsense/answer/9189560?hl=en).
5. Add only the CSP hosts required by the observed ad network requests. Retain the nonce and existing XSS protections; do not solve ad blocking by removing CSP.
6. Start with manually placed units and a control cohort. Evaluate total contribution per session: ad revenue + sponsorship revenue + subscription and print gross profit. Monitor viewability, fill rate, page RPM, page speed, download completion and sign-up conversion.
7. Consider Auto ads after the manually controlled baseline. Use page and area exclusions and test before expanding inventory. [Google Auto ads settings](https://support.google.com/adsense/answer/9261307?hl=en) provide exclusions and an experiment option.

Launch criterion: improved total contribution per session with acceptable page performance and no material drop in downloads or paid conversions. Ad count alone is not the optimization target. Ads are not enabled by this logo release.
