#!/usr/bin/env python3
"""Generate corrected MHD ITICS-family card templates (front+back fields_json)
per division, from the base tpl-front/back.json (correct positions) + the clean
redacted bg. Person fields dynamic bilingual; division/entity/tel/fax per-division.
Emits div-<slug>-{front,back}.json + a manifest.json for the PHP template creator.
"""
import json, copy

W2A = str.maketrans('0123456789', '٠١٢٣٤٥٦٧٨٩')
def ar_digits(s): return s.translate(W2A)

ENTITY_EN = 'Mohsin Haider Darwish L.L.C.'
ENTITY_AR = 'محسن حيدر درويش ش.م.م.'

# Group A divisions (share the ITICS design). slug matches the department slug.
# tel1/tel2/fax are the office numbers (Western digits, no +968 prefix - the
# "+968" is baked in the bg label).
DIVS = [
  # slug, division_en, division_ar, tel1, tel2, fax
  ('itics',              '',                                  '',                        '24835500','24732501','24830946'),
  ('ipd',                'Industrial Products Division',      'حلول المنتجات الصناعية',   '24835500','24837752','24830946'),
  ('tech-comm',          'Technology & Communications',       'التكنولوجيا والاتصالات',   '24835500','24837752','24830946'),
  ('healthcare',         'Healthcare Division',               'رعاية صحية',               '24835500','24831599','24830946'),
  ('office-products',    'Office Products Division',           'حلول المنتجات المكتبية',   '24837752','',        '24830946'),
  ('infrastructure',     'Infrastructure & Building Systems',  'البنية التحتية ونظم البناء','24732300','',       '24732505'),
  ('building-materials', 'Building Materials Division',        'مواد البناء',              '24794655','',        '24798662'),
]

front0 = json.load(open('tpl-front.json'))
back0  = json.load(open('tpl-back.json'))

def build_front(d_en, t1, t2, fax):
    f = copy.deepcopy(front0)
    out = {}
    for k, v in f.items():
        if k == 'static_6':   # was "Tel: +968 24788430" baked; number now a field, label in bg
            continue          # drop (label kept in clean bg via static_7 duplicate); tel1 added below
        if k == 'mobile':     v = dict(v, is_static=True,  render_in_bg=False, detected_text=t2); out['tel2']=v; continue
        if k == 'mobile_2':   v = dict(v, is_static=True,  render_in_bg=False, detected_text=fax); out['fax']=v; continue
        if k == 'mobile_3':   v = dict(v, is_static=False, render_in_bg=False, detected_text=''); out['mobile']=v; continue
        if k == 'static_10':  v = dict(v, is_static=True,  render_in_bg=False, detected_text=d_en); out['division_en']=v; continue
        if k == 'static_12':  v = dict(v, is_static=True,  render_in_bg=False, detected_text=ENTITY_EN); out['entity_en']=v; continue
        out[k] = v
    # tel1 field: clone the (now dropped) static_6 position for the number at x=853
    src = front0['static_6']
    out['tel1'] = dict(src, is_static=True, render_in_bg=False, x=853, width=121, fill='#3e71a5', color='#3e71a5', detected_text=t1)
    if not t2:  out.pop('tel2', None)
    return out

def build_back(d_ar, t1, t2, fax):
    b = copy.deepcopy(back0)
    out = {}
    for k, v in b.items():
        if k == 'name_ar_2':  v = dict(v, is_static=False, render_in_bg=False, detected_text=''); out['position_ar']=v; continue
        if k == 'static_5':   v = dict(v, is_static=False, render_in_bg=False, detected_text=''); out['position_ar_2']=v; continue
        if k == 'mobile':     v = dict(v, is_static=True,  render_in_bg=False, detected_text=ar_digits(t1)); out['tel1_ar']=v; continue
        if k == 'mobile_2':   v = dict(v, is_static=True,  render_in_bg=False, detected_text=ar_digits(t2)); out['tel2_ar']=v; continue
        if k == 'mobile_3':   v = dict(v, is_static=True,  render_in_bg=False, detected_text=ar_digits(fax)); out['fax_ar']=v; continue
        if k == 'mobile_4':   v = dict(v, is_static=False, render_in_bg=False, detected_text=''); out['mobile_ar']=v; continue
        if k == 'static_11':  v = dict(v, is_static=True,  render_in_bg=False, detected_text=d_ar); out['division_ar']=v; continue
        if k == 'static_12':  v = dict(v, is_static=True,  render_in_bg=False, detected_text=ENTITY_AR); out['entity_ar']=v; continue
        out[k] = v
    if not t2:  out.pop('tel2_ar', None)
    return out

manifest = []
for slug, d_en, d_ar, t1, t2, fax in DIVS:
    fj = build_front(d_en, t1, t2, fax)
    bj = build_back(d_ar, t1, t2, fax)
    if not d_en:  # ITICS umbrella: no division line
        fj.pop('division_en', None); bj.pop('division_ar', None)
    json.dump(fj, open(f'div-{slug}-front.json','w'), ensure_ascii=False)
    json.dump(bj, open(f'div-{slug}-back.json','w'), ensure_ascii=False)
    manifest.append({'slug':slug,'division_en':d_en,'division_ar':d_ar})
    print(f"built {slug}: front {len(fj)} fields, back {len(bj)} fields")

json.dump(manifest, open('div-manifest.json','w'), ensure_ascii=False, indent=1)
print("manifest:", len(manifest), "divisions")
