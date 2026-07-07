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

- Per-person variable fields: name_en/ar, title_en/ar, subtitle_en/ar (e.g.
  "Mobile Devices Sales"), division/entity line_en/ar, mobile, tel, fax, email.
- Implication: ONE template pair (front EN + back AR) covers every division; the
  division dropdown sets routing + the division/entity text line. **Task 4
  (per-division import loop) is eliminated.**

## CORRECTION (2026-07-07): NO importable clean master exists in the archive
Classified every candidate card PDF (pdffonts/pdfimages/pdfinfo). **All are
FLATTENED RASTER proofs** with the card text baked into pixels; the only live
fonts present are correction annotations. Cardify's import pipeline
(parse_card_pdf.py) needs a live text layer to auto-place dynamic fields, so NONE
import cleanly.
- `mhd-group-card-master.pdf` (ITICS-VC) = 72 ppi JPEG, 6pp, screen export - looks
  clean but is low-res + no text -> unusable for print AND unimportable.
- KKDURAI-final 150ppi, Sample-BusCard 254ppi, ipd-vishal 411ppi = raster proofs.
- Best available raster = ipd-vishal (411 ppi) / Sample-BusCard (254 ppi), but
  both have a specific person's data baked in.
**Decision needed (Ali):** (1) get the real print-ready SOURCE from BHD's design
team (InDesign/AI/press PDF with live text + brand font + logo asset - lives on
the design workstation, not email), OR (2) rebuild the MHD ITICS card natively as
a Cardify template (logo+banner as bg, define text fields) - needs the brand font
+ logo SVG from the design team. Option 1 is fastest to print-fidelity; option 2
is self-contained. "Import proofs now" is NOT viable - the proofs are raster.

## Existing MHD-tenant employees (already in Cardify, 33 total)
tech-comm 12, office-products 7, consumer 6, infrastructure 5, logistics 5,
building-materials 4, itics 2, healthcare 1; parent `mhd` + automotive = 0. Real
people (match the mail sweep). "Fold into parent" must decide: migrate these into
`mhd` or keep division tenants + span them. mhd.cardify.om/portal returns 200 and
already renders a department picker UI.
