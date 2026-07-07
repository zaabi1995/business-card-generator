# MHD division routing map (verified from BHD mail history, Jul 2026)

Source: grep of /www/vmail/bhdoman.com/{sales,info} maildirs for `mhd.co.om`,
covering 2023-2026 business-card / letterhead correspondence. Frequencies are
raw address-occurrence counts across both mailboxes (thread bloat inflates them,
use as relative signal only). DO NOT fabricate or alter these addresses.

## Division functional mailboxes ("person holding the department")
| Cardify tenant (slug)                       | Division                          | Division mailbox (CC target)        | Evidence |
|---------------------------------------------|-----------------------------------|-------------------------------------|----------|
| mhd-itics                                   | ITICS / CEO Office                | iticsceooffice@mhd.co.om            | 9238 (primary card requester) |
| mhd-tech-comm                               | Technology & Communications       | tech.comm@mhd.co.om                 | 6252 |
| mhd-office-products                         | Office Products Division          | opd@mhd.co.om                       | 2332 |
| mhd-infrastructure                          | Infrastructure & Building Systems | ibs@mhd.co.om                       | 2063 |
| (division TBC - EEP)                        | EEP                               | eep@mhd.co.om                       | 3136 (Muhammed Suhail / Mohammed Shaheer) |
| (division TBC - IPD)                        | IPD                               | ipd@mhd.co.om                       | 1674 (Abhishek Michyari) |
| mhd-healthcare                              | Healthcare                        | healthcare@mhd.co.om                | 1569 |

## Related ITICS sub-mailboxes seen (may be additional CC targets)
corpcom-itics@mhd.co.om (3800), cio-office@mhd.co.om (631),
iticsdirectoroffice@mhd.co.om (198), adv.sg-itics@mhd.co.om (637)

## Divisions with a Cardify tenant but NO verified card-order mailbox yet (ASK ALI)
mhd-automotive, mhd-consumer, mhd-building-materials, mhd-logistics, mhd (parent)

## Frequent individual MHD contacts (for reference, NOT department heads)
meena.alghailani@ (2621), buthaina.a@ (1660), aliakbar@ (1491), shakeel.ali@ (1421),
ahmedali@ (1401), john.rajan@ (1373), juhaina.d@ (1202), majda.mohamed@ (883),
devanand.v@ (740), sachin.s@ (684), rajeev.mehta@ (676), prateek.p@, irfanm@,
jyothish.t@, anandha.k@, nadeem.waheed@, panindernath.g@

## BHD side (always CC/from)
sales@bhdoman.com (Hamid Hussain handles MHD), info@bhdoman.com, accounts@bhdoman.com

## Cardify design status (verified 2026-07-07): 0 of 10 MHD tenants have a real
## card design imported. All templates are the auto-seeded BHD Classic placeholder.

## Design inventory (verified 2026-07-07 by rendering archive PDFs)
**MHD uses ONE group card design, not per-division artwork.** The card is the
"MHD ITICS" bilingual design: MHD+ITICS logo lockup, red/blue geometric banner
"INFRASTRUCTURE, TECHNOLOGY, INDUSTRIAL & CONSUMER SOLUTIONS" (AR mirror on the
back), and per-person text fields. Division is just a TEXT LINE on the card
(e.g. "Consumer Division / Mohsin Haider Darwish L.L.C." or "MHD Infrastructure
Services L.L.C."), NOT a different logo/layout.

- **Canonical master:** `docs/mhd/mhd-group-card-master.pdf` (was `ITICS-VC.pdf`,
  sent 4 May 2025). 6 pages = 3 employees x (EN front + AR back), CLEAN (no pen
  annotations), print-ready, bilingual. This is Cardify's exact front(EN)+back(AR)
  model. Use ONE employee's EN+AR pair (e.g. Sanju Varghese, pages 1-2) as the
  template; redact person data to dynamic fields via the import pipeline.
- Per-person variable fields: name_en/ar, title_en/ar, subtitle_en/ar (e.g.
  "Mobile Devices Sales"), division/entity line_en/ar, mobile, tel, fax, email.
- Implication: import the master ONCE into parent `mhd`; every department shares
  the same template pair. The division dropdown sets routing + the division/entity
  text line. **Task 4 (per-division import loop) is eliminated.**
- Other archive artwork seen (all the SAME design): KKDURAI draft.final.pdf,
  business card.pdf (17 Jun 2026), Business Card 27 Sept 23.pdf, Menon Old VC New
  draft.pdf. Confirms one unified design across years + divisions.
