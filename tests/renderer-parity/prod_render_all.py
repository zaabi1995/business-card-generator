#!/usr/bin/env python3
"""Render EVERY live vector template through the real pipeline and record the
exit code, so a change to render-card-pdf.py can be shown not to break any of
them. Uses each template's own fonts_dir, snapshotted read-only under
out/prod/fonts/<import-token>/.

Usage: prod_render_all.py            render on the working tree
       prod_render_all.py --head     render on git HEAD (restored afterwards)
"""
import json, pathlib, subprocess, sys, tempfile

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PROD = HERE / 'out' / 'prod'
sys.path.insert(0, str(HERE))
import harness as H

TRACKED = ['scripts/render-card-pdf.py', 'includes/CardPDFRenderer.php']


def render_all():
    blob = json.loads((PROD / 'fontlists.json').read_text())
    meta = blob['meta']
    rows = json.loads((PROD / 'all.json').read_text())
    out = {}
    for r in rows:
        m = meta.get(r['id'])
        if not m or m['hvs'] != '1' or not m['fonts_dir']:
            continue
        tok = m['fonts_dir'].rstrip('/').split('/')[-2]
        fdir = PROD / 'fonts' / tok
        if not fdir.is_dir():
            out[r['id']] = ('SKIP', 'no local fonts snapshot', 0)
            continue
        try:
            fields = json.loads(r['fields'] or '{}'); settings = json.loads(r['settings'] or '{}')
        except Exception:
            continue
        fx = {'id': 'p' + r['id'][:8], 'side': r['side'], 'settings': settings, 'fields': fields}
        with tempfile.TemporaryDirectory() as td:
            wd = pathlib.Path(td)
            p = wd / 'fx.json'; p.write_text(json.dumps(fx, ensure_ascii=False))
            try:
                spec = H.php_probe('pagespec', p)
            except Exception as e:
                out[r['id']] = ('ERR', f'pagespec {e}', 0); continue
            imp = wd / 'imp'; imp.mkdir()
            (wd / 't.json').write_text(json.dumps(
                {'pages': [spec], 'fonts_dir': str(fdir), 'import_dir': str(imp)}, ensure_ascii=False))
            (wd / 'e.json').write_text(json.dumps(H.EMPLOYEE, ensure_ascii=False))
            o = wd / 'o.pdf'
            res = subprocess.run(['python3', str(ROOT / 'scripts/render-card-pdf.py'),
                                  '--template', str(wd / 't.json'), '--employee', str(wd / 'e.json'),
                                  '--out', str(o)], capture_output=True, text=True)
            nspans = 0
            if o.exists():
                import fitz
                d = fitz.open(str(o))
                nspans = sum(len(l.get('spans', [])) for b in d[0].get_text('dict')['blocks']
                             for l in b.get('lines', []))
                d.close()
            out[r['id']] = (res.returncode, (res.stderr or '').strip()[:160], nspans)
    return out


def main():
    if '--head' in sys.argv:
        saved = {f: (ROOT / f).read_bytes() for f in TRACKED}
        try:
            for f in TRACKED:
                (ROOT / f).write_bytes(subprocess.check_output(['git', 'show', f'HEAD:{f}'], cwd=ROOT))
            res = render_all()
        finally:
            for f, b in saved.items():
                (ROOT / f).write_bytes(b)
        (PROD / 'renderall_head.json').write_text(json.dumps(res))
    else:
        res = render_all()
        (PROD / 'renderall_work.json').write_text(json.dumps(res))
    bad = {k: v for k, v in res.items() if v[0] not in (0, 'SKIP')}
    print(f"vector templates rendered: {len(res)}   non-zero exit: {len(bad)}")
    for k, v in bad.items():
        print(f"   {k[:8]} rc={v[0]} {v[1]}")
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
