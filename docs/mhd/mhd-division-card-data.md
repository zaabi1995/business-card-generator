# MHD per-division card data (verified from Drive designs, 7 Jul 2026)

Shared constants (all MHD LLC / ITICS divisions), VERBATIM:
- Entity EN: **Mohsin Haider Darwish L.L.C.** / AR: **محسن حيدر درويش ش.م.م.**
- Address: **P.O. Box 880, Postal Code 112, Ruwi, Muscat, Sultanate of Oman**
- Banner EN: **INFRASTRUCTURE, TECHNOLOGY, INDUSTRIAL & CONSUMER SOLUTIONS** / AR: **حلول البنية التحتية، التكنولوجية، الصناعية والاستهلاكية**
- Website: **www.mhditics.com** · Logo lockup: **MHD ITICS** · C.R. 1001990
  - CORRECTED 26 Jul 2026 (Ali). The original artwork baked `www.mhdoman.com` on all
    seven Group A cards, but MHD's own signature block uses `www.mhditics.com`. Swapped
    in `mhd-card-clean-bg.pdf` (both pages) via `scripts/mhd/fix-www-mhditics.py`, then
    all four shared backgrounds rebuilt with `build_ga_bgs.py`. Group B is unchanged:
    Automotive/Consumer/EEP keep mhdoman.com, Logistics keeps mhdlogistics.com.
    The line is baked artwork, not a field, so it does NOT appear in the PDF text layer,
    verify it by eye on a render, never with pdftotext.

## SOURCE-VERIFIED, 26 Jul 2026 (BHD mail + Drive artwork)
Mined `/www/vmail/bhdoman.com/{info,sales,purchase,accounts}` signature blocks and
`Drive > BHD > MHD`. What each source is actually good for:
- **Division letterheads** (`Drive > BHD > MHD > ITICS > <Division>.jpg`) carry the
  DIVISION's main tel/fax. Verified: Infrastructure & Building Systems 24732300 /
  24732505 / ibs@; Office Products 24837752 / 24830946 / opd@; Industrial Products
  24837752 / 24830946 / ipd@. These match the cards (IPD's card additionally carries
  24835500 as Tel1, which the letterhead does not show).
- **Card masters** carry the PERSON's direct line, not the division's. `ITICS Business
  Card.ai` (Ravishankar, Infrastructure) reads Tel 24732315 / Fax 24792505, both
  different from the IBS letterhead. So a per-division baked tel/fax is an
  approximation of what MHD actually prints. Branch staff are the clearest case:
  Madhu Pillai (Nizwa) signs 25412213 for both office and fax.
- **TRIM SIZES, all measured off MHD's own production files, 26 Jul 2026:**

  | Card | Trim | Source |
  |---|---|---|
  | ITICS family | 90 x 55mm (artwork 92 x 57, 1mm bleed) | `ITICS Business Card.ai` page box 260.79 x 161.57pt |
  | ACERE / Automotive | 90 x 55mm, no bleed | `ACERE - Business Card.ai` page box 255.12 x 155.91pt |
  | Consumer | 90 x 55mm (artwork 92 x 57) | cell on `MHD Consumer Services W.L.L V CARD 2025.ai`, x 167.18-427.97, bottom band flush to the bleed edge |
  | **Logistics** | **90 x 50mm** | **crop marks** in `MHD-Logistics/VC/*.ai`: verticals x 34.48 / 289.60 = 255.12pt = 90.00mm, horizontals y 38.85 / 180.58 = 141.73pt = 50.00mm |

  Everything except the two masters is an A4 gang sheet. Reading a trim off cell
  PITCH is what produced the wrong Logistics (95.62 x 52.57mm, that is the
  271.28 x 150.24pt pitch) and Consumer (127.76 x 62.79mm) templates. The Logistics
  sheet carries real crop marks, so measure those, never the pitch. Extract them by
  filtering `page.get_drawings()` for strokes with one dimension under 1.5pt.
- **Website counts across MHD signatures**: mhditics.com 1926, mhdoman.com 919,
  mhdacere.com 182. ITICS family -> mhditics.com (applied). The ACERE card master
  bakes mhdoman.com, so Automotive stays on mhdoman.com despite mhdacere.com in
  signatures, confirm with MHD before changing it.
- **Consumer has NO email line by design.** `MHD Consumer Services W.L.L V CARD
  2025.ai` gives every Bahrain card name / title / entity / address / Mob+Tel+Fax
  (+973) and no email at all. The Cardify template reproduces the artwork correctly,
  this is a question for MHD, not a bug to patch.

## GROUP A - share the ITICS card design (my clean bg works; only division-line + office tel/fax differ)
| Cardify dept | Division-line EN | Division-line AR | Tel1 +968 | Tel2 +968 | Fax +968 |
|---|---|---|---|---|---|
| ITICS (parent) | (umbrella - no division line, or "Human Resources") | — | 24732500 | 24732501 | 24793256 |
| IPD / Industrial Products | Industrial Products Division | حلول المنتجات الصناعية | 24835500 | 24837752 | 24830946 |
| Technology & Communications | TECHNOLOGY & COMMUNICATIONS | التكنولوجيا والاتصالات | 24835500 | 24837752 | 24830946 |
| Healthcare | HEALTHCARE | رعاية صحية | 24835500 | 24831599 | 24830946 |
| Office Products | OFFICE PRODUCTS DIVISION | حلول المنتجات المكتبية | 24837752 | — | 24830946 |
| Infrastructure & Building Systems | INFRASTRUCTURE & BUILDING SYSTEMS | البنية التحتية ونظم البناء | 24732300 (OCR) | — | 24732505 (OCR) |
| Building Materials | Building Materials Division | مواد البناء | 24794655 | — | **24792505** |

**Building Materials is a real division** (no standalone artwork in Drive, but staff
exist and sign as it). Verified 26 Jul 2026 from 5 distinct people in BHD's mail
(Hafizur Rehman Siddiqui 71557238, plus mobiles 92958751 / 71557622 / 79451199 /
71557609): office **24794655**, fax **24792505**, on every one of 182 matches.
The template previously baked fax `24798662`, which is the **CONSUMER division's**
fax (Muhammed Suhail, eep@mhd.co.om) and appears in zero Building Materials
signatures. It entered through the unsourced hardcoded row in `build_templates.py`.
Corrected in the live template 26 Jul 2026. Coordinators who cover both Building
Materials and Industrial Products sign office 24788933 instead, so 24794655 is the
Building Materials line proper.

## GROUP B - SEPARATE brand/design (own logo, NO ITICS banner; each needs its own card imported)
| Cardify dept | Division-line EN | Division-line AR | Entity | Tel +968 | Fax +968 | Logo | Website | Notes |
|---|---|---|---|---|---|---|---|---|
| Automotive | (separate LLC) | — | Mohsin Haider Darwish Automotive & Heavy Equipment LLC (محسن حيدر درويش للسيارات والمعدات الثقيلة ش.م.م.) | 24732500 | 24793256 | MHD Automotive | mhdoman.com | C.R. 1429946; letterhead 1z2hBagyUfrtK5F8Yr_ByTlj7dWjaXNzU |
| Consumer | CONSUMER / المستهلك | | Mohsin Haider Darwish for Consumer Services W.L.L (محسن حيدر درويش لخدمات المستهلك ذ.م.م.) | 24788933 | — | MHD CONSUMER ITICS | mhdoman.com | BAHRAIN entity: Office 1967, Bldg 1565, Road 1722, Block 317, Manama; Licence 184344-1. src 1b8WdLYFWHrtAxssIQtjHNxyMEMcVViJ3 |
| Logistics | LOGISTICS / الخدمات اللوجستية | | Mohsin Haider Darwish Logistics L.L.C. (محسن حيدر درويش اللوجستية ش.م.م.) | (mobile-only, no office tel) | — | MHD LOGISTICS L.L.C | **www.mhdlogistics.com** | Different address: P.O. Box 112, Postal Code 111, Muscat. src 14_Zcj8cWeZhPTYhMl3QNJ-s9w6q_Bm5W |
| EEP / Engineering Products | ENGINEERING PRODUCTS / المنتجات الهندسية | | A Division of Mohsin Haider Darwish LLC (قسم من محسن حيدر درويش ش.م.م) | 26841087 | 24210888 | MHD (brand strip: XCMG, Mitsubishi, Terex, Finlay, Tennant, Chicago Pneumatic, FG Wilson, ABUS, Winget, esquire) | mhdoman.com | src jpgs 1OPWw9.., 1SJSKsq.. (OCR) |

## Field split (per Ali): office Tel/Fax = per-division FIXED (baked per template). Per-person dynamic = name/position/subtitle (ALL EN+AR) + mobile + email.
## OCR numbers (Office Products, Infra&BS, EEP, Consumer) = re-confirm against art before print. Others exact from live text layer.
## Drive roots: MHD Visiting Card 1hwgPtWaodmpzThf4Jqfz9EqpYDgWHeI9; All Cards Print File 1YtLtCDQZLcBaDp2PPP0Im2SkNYQcUz0c (~300 per-employee Data Set N.ai).

## GROUP B verified layouts (Drive vector cards, extracted 7 Jul) - trim 90x55mm = 255.1x155.9pt
Full per-span field maps in tool-results/bmghxgp3j.txt. Buildable = Logistics, Consumer, Automotive(Imran). EEP = needs artwork.

**Logistics** (BUILDABLE 2-sided EN+AR, cleanest) src 14_Zcj8cWeZhPTYhMl3QNJ-s9w6q_Bm5W:
- Logo: MHD LOGISTICS L.L.C + label "LOGISTICS". NO ITICS banner. Entity EN "Mohsin Haider Darwish Logistics L.L.C." / AR "محسن حيدر درويش اللوجستية ش.م.م."
- Address: P.O. Box 112, Postal Code 111, Muscat (DIFFERENT from ITICS 880/112). Web www.mhdlogistics.com. NO Tel/Fax line (mobile+email only). Person: name, title, mobile, email, web.
- Colors navy #0f1f5c, blue #0662ae. Fonts FrutigerLTStd + FrutigerLTArabic.

**Consumer** (BUILDABLE, ENGLISH FRONT ONLY - no Arabic back in artwork) src 1b8WdLYFWHrtAxssIQtjHNxyMEMcVViJ3:
- ITICS banner ("INFRASTRUCTURE, TECHNOLOGY, INDUSTRIAL & CONSUMER SOLUTIONS"). Entity "Mohsin Haider Darwish for Consumer Services W.L.L" (BAHRAIN). Address Office 1967, Building 1565, Road 1722, Block 317, Manama, Kingdom of Bahrain. Tel/Fax +973 placeholder 00000000 (per-person real). NO division line/website/PO-box on the card.

**Automotive** (BUILDABLE 2-sided, = the "Imran Safdar Khan" card) src 1VjgKC.. "Print-Imran Safdar Khan - Engineering Products.ai" (MIS-TITLED, it's the Automotive/Construction-Equip card):
- Banner EN "AUTOMOTIVE, CONSTRUCTION EQUIPMENT & RENEWABLE ENERGY" / AR "السيارات، معدات البناء والطاقة المتجددة". Entity = PARENT "Mohsin Haider Darwish L.L.C." (CR 1001990, NOT Heavy Equipment LLC). Address P.O. Box 880/112 Ruwi. Tel +968 26841087 (Direct), person Mob. Division line "Construction Equipment" / "معدات البناء". Web mhdoman.com.
- The NEW "Automotive & Heavy Equipment LLC" (CR 1429946, Tel 24732500/Fax 24793256) has ONLY a letterhead -> NEEDS-ARTWORK if that entity's card is wanted.

**EEP** (eep@mhd.co.om, Muhammed Shaheer) = NEEDS-ARTWORK: only a flat "Confirmation mhd card.jpg" proof (200pcs, 90x55mm, 300gsm) + ITICS letterhead. Rebuild from JPG or reuse ITICS template w/ eep@ contact block.
