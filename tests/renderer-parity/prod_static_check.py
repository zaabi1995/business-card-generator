#!/usr/bin/env python3
"""Production check for the static-anchor change (RENDERER_VERSION 25).

Renders every live template that carries a non-baked static field through the
REAL CardPDFRenderer::pageSpec + render-card-pdf.py, with that template's own
fonts, on the current working tree and on git HEAD, and diffs the resulting
text spans.

  AFFECTED  = has >=1 non-baked static with textAlign right/center. Expect the
              static to move onto the Fabric anchor.
  NO-OP     = only left-aligned non-baked statics. Expect byte-identical spans.

Inputs come from tests/renderer-parity/out/prod/all.json (pulled read-only from
production MySQL) and out/prod/fonts/<import-dir>/.
"""
import json, pathlib, subprocess, sys, tempfile
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import harness as H

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PROD = HERE / 'out' / 'prod'
FONTS = PROD / 'fonts'


def load():
    rows = json.loads((PROD / 'all.json').read_text())
    fdmap = json.loads((PROD / 'fontsmap.json').read_text())
    out = []
    for r in rows:
        try:
            f = json.loads(r['fields'] or '{}'); s = json.loads(r['settings'] or '{}')
        except Exception:
            continue
        if not isinstance(f, dict):
            continue
        nb = [(k, v) for k, v in f.items() if isinstance(v, dict) and v.get('is_static')
              and not v.get('render_in_bg') and not ('enabled' in v and not v['enabled'])]
        if not nb:
            continue
        nl = [k for k, v in nb if (v.get('textAlign') or 'left') != 'left']
        d = fdmap.get(r['id'], '')
        fdir = FONTS / d if d and (FONTS / d).is_dir() else None
        out.append({'id': r['id'], 'name': r['name'], 'side': r['side'],
                    'fx': {'id': 'prod_' + r['id'][:8], 'side': r['side'],
                           'description': 'live template', 'settings': s, 'fields': f},
                    'nonleft': nl, 'fonts': fdir})
    return out


def spans(fx, fonts, wd, tag):
    p = wd / f'{tag}.json'
    p.write_text(json.dumps(fx, ensure_ascii=False))
    spec = H.php_probe('pagespec', p)
    sp, rect, _ = H.r2_render(spec, wd, tag, fonts)
    return sorted(sp), rect


def main():
    cases = load()
    cur = (ROOT / 'includes/CardPDFRenderer.php').read_bytes()
    old = subprocess.check_output(['git', 'show', 'HEAD:includes/CardPDFRenderer.php'], cwd=ROOT)
    results = []
    for c in cases:
        if c['fonts'] is None:
            results.append((c, 'SKIP no fonts', None, None)); continue
        with tempfile.TemporaryDirectory() as td:
            wd = pathlib.Path(td)
            try:
                (ROOT / 'includes/CardPDFRenderer.php').write_bytes(old)
                before, _ = spans(c['fx'], c['fonts'], wd, 'before')
            finally:
                (ROOT / 'includes/CardPDFRenderer.php').write_bytes(cur)
            after, _ = spans(c['fx'], c['fonts'], wd, 'after')
        moved = [(b, a) for b, a in zip(before, after) if b != a] if len(before) == len(after) else None
        results.append((c, 'AFFECTED' if c['nonleft'] else 'NO-OP', before, after))

    print(f"{'id':9s} {'name':26s} {'side':5s} {'class':9s} {'spans':>6s}  verdict")
    print('-' * 96)
    bad = 0
    for c, kind, b, a in results:
        if b is None:
            print(f"{c['id'][:8]} {c['name'][:26]:26s} {c['side']:5s} {kind}"); continue
        same = (b == a)
        if kind == 'NO-OP':
            ok = same
            v = 'identical spans, OK' if same else '*** MOVED, REGRESSION ***'
        else:
            ok = not same
            v = f"moved as intended ({len(c['nonleft'])} static)" if not same else '*** DID NOT MOVE ***'
        bad += 0 if ok else 1
        print(f"{c['id'][:8]} {c['name'][:26]:26s} {c['side']:5s} {kind:9s} {len(b):6d}  {v}")
    print(f"\n{'ALL AS EXPECTED' if bad == 0 else f'{bad} UNEXPECTED'}")
    return 1 if bad else 0


if __name__ == '__main__':
    raise SystemExit(main())
