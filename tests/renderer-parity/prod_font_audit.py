#!/usr/bin/env python3
"""Audit: can render-card-pdf.py's _pick_font fail to resolve a family on a LIVE
template, which makes the field vanish from the printed PDF with exit code 0?

Replicates _pick_font (scripts/render-card-pdf.py:126-175) exactly:
  - Arabic content: any buffer whose STEM contains 'arabic' resolves it.
  - Otherwise:      resolves only if family.lower() is a substring of a stem.
  - No match:       returns (None, None), and the field loop at :1263 `continue`s.

Font buffers = templates.fonts_dir + uploads/fonts/companies/<company_id>
(_load_font_buffers, :89-108). Inputs are read-only snapshots in out/prod/.
"""
import json, pathlib, sys, re

PROD = pathlib.Path(__file__).resolve().parent / 'out' / 'prod'
RTL = re.compile('[֐-ࣿݐ-ݿﭐ-﷿ﹰ-﻿]')


def resolves(family: str, stems: list, arabic: bool) -> bool:
    fl = (family or '').lower()
    if arabic and any('arabic' in s.lower() for s in stems):
        return True
    return any(fl in s.lower() for s in stems)


def main():
    blob = json.loads((PROD / 'fontlists.json').read_text())
    meta, listing = blob['meta'], blob['listing']
    rows = json.loads((PROD / 'all.json').read_text())

    def stems_for(tid):
        m = meta.get(tid, {})
        files = list(listing.get(m.get('fonts_dir', ''), []))
        files += list(listing.get(m.get('cid', ''), []))
        return [pathlib.Path(f).stem for f in files
                if f.lower().endswith(('.ttf', '.otf'))]

    risk = []
    scanned = 0
    for r in rows:
        m = meta.get(r['id'])
        if not m or m['hvs'] != '1':
            continue          # non-vector templates never reach render-card-pdf.py
        stems = stems_for(r['id'])
        try:
            fields = json.loads(r['fields'] or '{}')
        except Exception:
            continue
        if not isinstance(fields, dict):
            continue
        scanned += 1
        for k, f in fields.items():
            if not isinstance(f, dict) or k == 'qr_code':
                continue
            if f.get('render_in_bg'):
                continue
            if 'enabled' in f and not f['enabled']:
                continue
            fam = f.get('fontFamily') or f.get('font_family') or 'Lato'
            sample = str(f.get('detected_text') or '')
            is_ar = bool(RTL.search(sample)) or k.endswith('_ar')
            # A dynamic field's real text is the employee's, so judge both:
            # latin content is the strict case, arabic content the lenient one.
            ok_latin = resolves(fam, stems, arabic=False)
            ok_arabic = resolves(fam, stems, arabic=True)
            if ok_latin:
                continue
            risk.append({'id': r['id'][:8], 'name': r['name'][:26], 'side': r['side'],
                         'field': k, 'family': fam, 'static': bool(f.get('is_static')),
                         'ar_key': is_ar, 'saved_by_arabic_fallback': ok_arabic,
                         'stems': len(stems), 'dir': m['fonts_dir'].split('/')[-2] if m['fonts_dir'] else '-'})

    print(f"vector templates scanned: {scanned}")
    print(f"drawn fields whose family does NOT substring-match any available font: {len(risk)}\n")
    hard = [x for x in risk if not x['saved_by_arabic_fallback']]
    soft = [x for x in risk if x['saved_by_arabic_fallback']]
    print(f"  HARD (no fallback at all -> field IS dropped): {len(hard)}")
    for x in hard:
        print(f"    {x['id']} {x['name']:26s} {x['side']:5s} {x['field']:14s} "
              f"family={x['family']!r} static={x['static']} fonts={x['stems']} dir={x['dir']}")
    print(f"\n  SOFT (only saved if the text is Arabic, via the 'any arabic' branch): {len(soft)}")
    for x in soft:
        print(f"    {x['id']} {x['name']:26s} {x['side']:5s} {x['field']:14s} "
              f"family={x['family']!r} ar_key={x['ar_key']} static={x['static']} dir={x['dir']}")
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
