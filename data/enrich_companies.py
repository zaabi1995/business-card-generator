#!/usr/bin/env python3
"""
Enrich Oman enterprise list (2,414 rows) with slug, sector, wilayat, size.

Input : oman-enterprises.xlsx  (columns: NAME_ENG, NAME_ARA)
Output: oman-enterprises-enriched.csv
         (columns: id, name_en, name_ar, slug, sector, wilayat, size)

Sectors  : 23 categories, fallback -> 'other'
Wilayat  : 11 Oman governorates, fallback -> 'muscat'
Size     : first 1000 rows -> 'large', rest -> 'medium'

Rerunnable: overwrites the CSV each run.
"""
from __future__ import annotations

import re
import sys
from collections import Counter
from pathlib import Path

import pandas as pd
from slugify import slugify

DATA_DIR = Path(__file__).resolve().parent
INPUT_XLSX = DATA_DIR / "oman-enterprises.xlsx"
OUTPUT_CSV = DATA_DIR / "oman-enterprises-enriched.csv"

# ---------------------------------------------------------------------------
# 1. Slug generation
# ---------------------------------------------------------------------------

# Legal suffixes / corporate-form tokens to strip before slugifying.
# Handled case-insensitively with word boundaries.
LEGAL_STOPWORDS = [
    r"l\.l\.c\.?",
    r"llc",
    r"s\.a\.o\.c\.?",
    r"s\.a\.o\.g\.?",
    r"saoc",
    r"saog",
    r"s\.p\.c\.?",
    r"spc",
    r"s\.s\.c\.?",
    r"ssc",
    r"co\.?",
    r"company",
    r"companies",
    r"corp\.?",
    r"corporation",
    r"ltd\.?",
    r"limited",
    r"est\.?",
    r"establishment",
    r"ent\.?",
    r"enterprises?",
    r"sh\.?m\.?m\.?",
    r"sh\s*m\s*m",
    r"w\.?l\.?l\.?",
    r"p\.?l\.?c\.?",
    r"plc",
    r"inc\.?",
]
LEGAL_RE = re.compile(
    r"(?i)(?<![a-z])(?:" + r"|".join(LEGAL_STOPWORDS) + r")(?![a-z])"
)


def make_slug(name: str) -> str:
    """Kebab-case slug stripped of legal-form suffixes and trailing numeric dupes."""
    if not isinstance(name, str):
        return ""
    cleaned = LEGAL_RE.sub(" ", name)
    # Collapse punctuation the regex left behind (dots, ampersands, commas).
    cleaned = re.sub(r"[&]", " and ", cleaned)
    base = slugify(cleaned, lowercase=True, separator="-")
    # Remove trailing "-\d+" that would collide with our dedupe suffixes.
    base = re.sub(r"(?:-\d+)+$", "", base)
    return base or "company"


def dedupe_slugs(slugs: list[str]) -> tuple[list[str], int]:
    """Append -2, -3, ... for duplicates. Returns final list + dup count."""
    seen: Counter[str] = Counter()
    final: list[str] = []
    dupes = 0
    for s in slugs:
        seen[s] += 1
        if seen[s] == 1:
            final.append(s)
        else:
            dupes += 1
            final.append(f"{s}-{seen[s]}")
    return final, dupes


# ---------------------------------------------------------------------------
# 2. Sector classification
# ---------------------------------------------------------------------------

# Order matters: first match wins. More specific patterns first, broad catch-alls later.
SECTOR_RULES: list[tuple[str, list[str]]] = [
    ("oil-gas", [
        "oil ", " oil", "petroleum", "petrol", " lng", "lng ", "refinery",
        "refineries", "oilfield", "oilfields", "drilling", "upstream",
        "downstream", "petrochemical", "fuel", "filling station",
        "filling stations", "wellhead", "halliburton", "schlumberger",
        "baker hughes", "weatherford", "production services",
        "نفط", "بترول", "غاز", "تكرير", "حقول النفط", "بتروكيماوي",
        "محطات الوقود", "محطة الوقود", "محطة تعبئة", "محطات تعبئة", "وقود",
    ]),
    ("mining", [
        "mining", "quarry", "quarries", "minerals", "marble", "gypsum",
        "stone ", " stone", "limestone", "granite",
        "تعدين", "محاجر", "معادن", "حجر", "الرخام",
    ]),
    ("telecom", [
        "telecom", "telecommunication", "telecommunications", "mobile network",
        "telephone services",
        "اتصالات", "خدمات الهاتف",
    ]),
    ("technology", [
        "software", "technolog", " it ", "it services", " digital", "digital ",
        "cyber", "information technology", "systems integration", "data center",
        "it solutions", "technical solutions", "tech solutions", "techno ",
        "systems solutions", "techno park", "cloud ",
        "تكنولوجيا", "معلومات", "برمجيات", "رقمي", "التقنية", "تقنية", "تقنيات",
        "حديقة التقنية",
    ]),
    ("finance", [
        "bank", "banking", "finance", "financial", "leasing", "insurance",
        "capital", "securities", "brokerage", "exchange", "microfinance",
        "islamic bank",
        "بنك", "مصرف", "تمويل", "تأمين", "مالية", "صرافة", "استثمار مالي",
    ]),
    ("real-estate", [
        "real estate", "realty", "properties", "property", "estates",
        "development", "developments", "developers", "housing",
        "عقار", "عقارات", "أملاك", "اسكان", "إسكان",
    ]),
    ("construction", [
        "construction", "contracting", "contractors", "contractor",
        "engineering", "engineers", "engg", "civil works", " works",
        "infrastructure", "building", "builders", "builder ", "epc",
        "readymix", "ready mix", "ready-mix", "concrete", "precast",
        "scaffolding", "operation and maintenance", "operations and maintenance",
        "o&m",
        "مقاولات", "بناء", "انشاء", "إنشاء", "هندسية", "هندسة", "أعمال",
        "الخرسانة", "خرسانة", "التشغيل والصيانة",
    ]),
    ("logistics-shipping", [
        "logistics", "shipping", "freight", "cargo", "transport",
        "transportation", "forwarding", "forwarder", "clearance",
        "supply chain", "warehousing", "haulage", "marine services",
        " port ", " ports ", "port services", "ports services",
        " port.", " port,", "port-", "terminal", "terminals", "container ",
        "asyad", "airlines", "airways", "aviation", "air cargo", " air ",
        "air.", "airline",
        "لوجستية", "لوجستيات", "شحن", "نقل", "تخليص", "ملاحة",
        "المواني", "ميناء", "موانئ", "الحاويات", "طيران",
    ]),
    ("automotive", [
        "auto ", "automotive", "motors", "motor ", "vehicle", "vehicles",
        "cars", "truck", "trucks", "tyre", "tyres", "tire", "tires",
        "سيارات", "مركبات", "محركات",
    ]),
    ("food-beverage", [
        "food", "foods", "beverage", "beverages", "restaurant", "restaurants",
        "catering", "bakery", "bakeries", "mills", "dairy", "confectionery",
        "coffee", "f & b", "f&b", "refreshment", "refreshments", " tea ",
        "tea company", "juice", "ice cream", "ice-cream", "meat ", "poultry",
        "flour ", "hotpack",
        "اغذية", "أغذية", "مطعم", "مطاعم", "مخبز", "مخابز", "مطاحن",
        "تموين", "مشروبات", "ألبان", "حلويات", "مرطبات", "الشاي", "لحوم",
    ]),
    ("hospitality-tourism", [
        "hotel", "hotels", "resort", "resorts", "tours", "tourism", "travel",
        "hospitality", "cruise",
        "فندق", "فنادق", "منتجع", "منتجعات", "سياحة", "سياحية", "سفر",
    ]),
    ("healthcare", [
        "medical", "medicine", "hospital", "hospitals", "clinic", "clinics",
        "pharma", "pharmacy", "pharmacies", "pharmaceutical", "health",
        "healthcare", "diagnostic", "dental",
        "طبي", "طبية", "مستشفى", "عيادة", "صيدلية", "صيدليات", "دواء", "صحة",
    ]),
    ("education", [
        "school", "schools", "university", "college", "colleges", "academy",
        "institute", "training", "educational", "education", "learning",
        "مدرسة", "مدارس", "جامعة", "كلية", "أكاديمية", "معهد", "تدريب", "تعليم",
    ]),
    ("agriculture-fisheries", [
        "fishing", "fisheries", "fish ", "seafood", "agriculture",
        "agricultural", "farm", "farms", "farming", "poultry", "livestock",
        "aquaculture", "dates",
        "أسماك", "صيد", "زراعة", "زراعية", "مزرعة", "مزارع", "دواجن",
    ]),
    ("utilities", [
        "power", "electricity", "electric", "water", "desalination", "energy",
        "renewable", "solar", "sewage", "waste water", "utilities",
        "كهرباء", "مياه", "طاقة", "تحلية", "صرف صحي",
    ]),
    ("media-advertising", [
        "media", "advertising", "advert", "marketing", "publishing", "press",
        "broadcast", "film", "production house", "publicity",
        "إعلام", "اعلام", "إعلان", "اعلان", "دعاية", "تسويق", "نشر", "صحافة",
    ]),
    ("professional-services", [
        "consult", "consulting", "consultancy", "consultants", "legal",
        "law firm", "advocate", "advocates", "audit", "auditing", "auditors",
        "accounting", "accountants", "tax ", "chartered",
        "استشارات", "استشارية", "محاماة", "محامون", "تدقيق", "محاسبة", "قانونية",
    ]),
    ("government-defense", [
        "defense", "defence", "military", "armed forces", "armaments",
        "ammunition", "weapons",
        "دفاع", "عسكري", "ذخائر", "ذخيرة", "أسلحة", "القوات المسلحة",
    ]),
    ("retail", [
        "retail", "supermarket", "hypermarket", "mall", "stores", "shop",
        "shops", "boutique", "department store",
        "تجزئة", "هايبر", "سوبرماركت", "بوتيك",
    ]),
    ("manufacturing", [
        "plastics", "plastic", "steel", "iron", "cement", "industries",
        "industrial", "factory", "factories", "manufacturing", "manufacturer",
        "fabrication", "fabricators", "aluminium", "aluminum", "glass works",
        "paper mills", "textile", "textiles", "ceramic", "ceramics", "tiles",
        "chemical", "chemicals", "chlorine", "formaldehyde", "polymer",
        "polymers", "paints", "pipes", "pipe ", "transformers", "switchgear",
        "switchgears", "fertilizer", "fertilizers", "apparel", "garments",
        "cotton", "jewellery", "jewelry", "jewelers", "jewellers",
        "radiators", "cooling systems", "electronics",
        "electrical ", " electrical", "equipment manufacturing",
        "مصنع", "مصانع", "صناعة", "صناعية", "صناعات", "حديد", "اسمنت", "إسمنت",
        "بلاستيك", "ألمنيوم", "سيراميك", "بلاط", "كيماوية", "الكيميائية",
        "الكهربائية", "الالكترونيات", "الإلكترونيات", "المجوهرات", "للمجوهرات",
        "الكساء", "القطن",
    ]),
    ("trading", [
        "trading", "traders", " trade", "trade ", "trad ", " trad.", " trad,",
        "trdg", "commerce", "commercial", "general trading", "merchants",
        "تجارة", "تجارية", "للتجارة", "تجاري",
    ]),
    ("conglomerate", [
        " group", "group ", "holding", "holdings", "investment", "investments",
        "enterprises group", "international group",
        "مجموعة", "القابضة", "قابضة", "استثمار", "استثمارات",
    ]),
    ("construction", [  # second pass for Arabic "projects / مشاريع" (mostly contracting ventures)
        "مشاريع", "للمشاريع", "المشاريع", " projects", "projects ",
        "special projects",
    ]),
]


def classify_sector(name_en: str, name_ar: str) -> str:
    """Return first sector whose keyword list matches either name. Fallback 'other'."""
    hay = f" {str(name_en).lower()} | {str(name_ar)} "
    for sector, keywords in SECTOR_RULES:
        for kw in keywords:
            if kw in hay:
                return sector
    return "other"


# ---------------------------------------------------------------------------
# 3. Wilayat / governorate detection
# ---------------------------------------------------------------------------

# Map place tokens -> governorate slug. Tokens checked case-insensitively.
WILAYAT_RULES: list[tuple[str, list[str]]] = [
    ("dhofar", [
        "dhofar", "salalah", "mirbat", "taqah", "thumrait", "rakhyut",
        "ظفار", "صلالة", "مرباط", "طاقة", "ثمريت",
    ]),
    ("musandam", [
        "musandam", "khasab", "bukha", "madha",
        "مسندم", "خصب", "بخا", "مدحاء",
    ]),
    ("al-buraimi", [
        "buraimi", "al buraimi", "al-buraimi", "mahdah",
        "البريمي", "بريمي", "محضة",
    ]),
    ("al-wusta", [
        "duqm", "haima", "mahout", "al jazer", "al-wusta", "wusta",
        "الدقم", "هيما", "محوت", "الجازر", "الوسطى",
    ]),
    ("al-dakhiliyah", [
        "nizwa", "bahla", "izki", "manah", "samail", "adam", "al hamra",
        "al-dakhiliyah", "dakhiliyah",
        "نزوى", "بهلاء", "ازكي", "إزكي", "منح", "سمائل", "أدم", "الحمراء",
        "الداخلية",
    ]),
    ("al-dhahirah", [
        "ibri", "yanqul", "dhank", "al-dhahirah", "dhahirah",
        "عبري", "ينقل", "ضنك", "الظاهرة",
    ]),
    ("al-sharqiyah-south", [
        "sur ", "sur-", "jalan", "jaalan", "ras al hadd", "ras al-hadd",
        "ras al-jinz", "kamil", "wafi", "masirah",
        "صور", "جعلان", "رأس الحد", "الكامل", "الوافي", "مصيرة",
    ]),
    ("al-sharqiyah-south-ibra-guard", [  # sentinel — handled specially below
        "__never_match__",
    ]),
    ("al-sharqiyah-north", [
        "ibra", "al mudhaibi", "mudhaibi", "bidiyah", "bidiya", "dima",
        "wattayah sharqi", "al qabil", "qabil",
        "إبراء", "المضيبي", "بدية", "دماء", "القابل",
    ]),
    ("al-batinah-north", [
        "sohar", "shinas", "liwa", "saham", "al khaburah", "khaburah",
        "صحار", "شناص", "لوى", "صحم", "الخابورة",
    ]),
    ("al-batinah-south", [
        "rustaq", "al rustaq", "barka", "nakhal", "wadi al maawil", "musannah",
        "al musannah", "al awabi", "awabi",
        "الرستاق", "بركاء", "نخل", "المصنعة", "العوابي",
    ]),
    ("muscat", [
        "muscat", "seeb", "al seeb", "bawshar", "bousher", "amerat",
        "al amerat", "qurum", "ruwi", "mutrah", "muttrah", "ghubra", "azaiba",
        "مسقط", "السيب", "بوشر", "العامرات", "القرم", "روي", "مطرح", "الغبرة",
        "العذيبة",
    ]),
]


def classify_wilayat(name_en: str, name_ar: str) -> str:
    hay = f" {str(name_en).lower()} | {str(name_ar).lower()} "
    for wilayat, keywords in WILAYAT_RULES:
        if wilayat == "al-sharqiyah-south-ibra-guard":
            continue
        for kw in keywords:
            if kw.lower() in hay:
                return wilayat
    return "muscat"


# ---------------------------------------------------------------------------
# 4. Main
# ---------------------------------------------------------------------------

SIZE_BOUNDARY = 1000  # first N rows -> large, rest -> medium


def main() -> int:
    if not INPUT_XLSX.exists():
        print(f"ERROR: input not found: {INPUT_XLSX}", file=sys.stderr)
        return 1

    df = pd.read_excel(INPUT_XLSX)
    required = {"NAME_ENG", "NAME_ARA"}
    missing = required - set(df.columns)
    if missing:
        print(f"ERROR: missing columns {missing}", file=sys.stderr)
        return 1

    total = len(df)
    print(f"Rows loaded: {total}")

    raw_slugs = [make_slug(n) for n in df["NAME_ENG"].astype(str)]
    final_slugs, dupe_count = dedupe_slugs(raw_slugs)

    sectors = [
        classify_sector(en, ar)
        for en, ar in zip(df["NAME_ENG"].astype(str), df["NAME_ARA"].astype(str))
    ]
    wilayats = [
        classify_wilayat(en, ar)
        for en, ar in zip(df["NAME_ENG"].astype(str), df["NAME_ARA"].astype(str))
    ]
    sizes = ["large" if i < SIZE_BOUNDARY else "medium" for i in range(total)]

    out = pd.DataFrame({
        "id": range(1, total + 1),
        "name_en": df["NAME_ENG"].astype(str).str.strip(),
        "name_ar": df["NAME_ARA"].astype(str).str.strip(),
        "slug": final_slugs,
        "sector": sectors,
        "wilayat": wilayats,
        "size": sizes,
    })
    out.to_csv(OUTPUT_CSV, index=False, encoding="utf-8")

    # --- Summary ------------------------------------------------------------
    print(f"\nWrote: {OUTPUT_CSV}")
    print(f"Rows processed: {total}")

    print("\nSector distribution:")
    sec_counts = Counter(sectors)
    for sec, n in sorted(sec_counts.items(), key=lambda kv: (-kv[1], kv[0])):
        pct = n / total * 100
        print(f"  {sec:<24} {n:>5}  ({pct:5.1f}%)")

    print("\nWilayat distribution:")
    wil_counts = Counter(wilayats)
    for wil, n in sorted(wil_counts.items(), key=lambda kv: (-kv[1], kv[0])):
        pct = n / total * 100
        print(f"  {wil:<24} {n:>5}  ({pct:5.1f}%)")

    print("\nSize distribution:")
    size_counts = Counter(sizes)
    for size, n in sorted(size_counts.items(), key=lambda kv: (-kv[1], kv[0])):
        print(f"  {size:<24} {n:>5}")

    # Integrity checks
    final_dup_check = len(final_slugs) - len(set(final_slugs))
    print(f"\nRaw duplicate slugs resolved via suffix: {dupe_count}")
    print(f"Remaining duplicate slugs (should be 0): {final_dup_check}")
    print(f"Unclassified (sector='other'): {sec_counts.get('other', 0)}")
    print(f"Fallback wilayat ('muscat'): {wil_counts.get('muscat', 0)}")

    # Show a sample of dedupe collisions (first 15)
    if dupe_count:
        seen: Counter[str] = Counter()
        collisions: list[tuple[int, str, str]] = []
        for i, (raw, final) in enumerate(zip(raw_slugs, final_slugs)):
            seen[raw] += 1
            if seen[raw] > 1:
                collisions.append((i + 1, raw, final))
        print(f"\nSample dedupe collisions (up to 15 of {len(collisions)}):")
        for row_id, raw, final in collisions[:15]:
            print(f"  row {row_id:>4}: {raw}  ->  {final}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
