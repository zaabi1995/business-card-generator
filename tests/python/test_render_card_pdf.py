"""
Renderer contract: given an import-dir + a fields_json (template) + employee
data, emit a 2-page vector PDF where every dynamic field is real text drawn
with the embedded fonts. The Otech sample data fixture exercises the
common case (4 dynamic fields per side, Lato-Medium body + Sora-Regular
name).
"""
import os
import json
import subprocess
import fitz
import pytest

OTECH_DIR = '/tmp/otech-pdf'

EMPLOYEE = {
    'id': 'muhammed.ali',
    'name_en': 'Muhammed Ali',
    'position_en': 'Product Manager',
    'mobile': 'M +968 9771 2345',
    'email': 'E muhammed.ali@otech.om',
    'address_en': 'H8JG+52V, Muscat, Oman',
    'website': 'www.otech.om',
}

# Two-page template fixture: front (mostly static + QR) + back (dynamic).
# Mirrors the shape parse_card_pdf.py emits.
TEMPLATE_FIXTURE = {
    'pages': [
        {'side': 'front', 'width_pt': 262.55, 'height_pt': 169.89,
         'background_svg_path': 'bg-page-1.svg', 'fields': []},
        {'side': 'back',  'width_pt': 262.55, 'height_pt': 169.89,
         'background_svg_path': 'bg-page-2.svg', 'fields': [
            {'field_key': 'name_en', 'x_pt': 31.8, 'y_pt': 48.4,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 13.1,
             'color': '#ffffff'},
            {'field_key': 'position_en', 'x_pt': 32.2, 'y_pt': 66.2,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 8.7,
             'color': '#ffffff'},
            {'field_key': 'mobile', 'x_pt': 32.6, 'y_pt': 97.7,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 7.6,
             'color': '#ffffff'},
            {'field_key': 'email', 'x_pt': 32.6, 'y_pt': 107.5,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 7.6,
             'color': '#ffffff'},
         ]},
    ],
    'fonts_dir': os.path.join(OTECH_DIR, 'fonts'),
}


@pytest.fixture
def otech_dir(tmp_path):
    """Snapshot the live Otech import dir + fonts into a tmp path."""
    import shutil
    dst = tmp_path / 'otech'
    shutil.copytree(OTECH_DIR, dst)
    return str(dst)


def test_renderer_emits_two_page_pdf(otech_dir, tmp_path):
    template_path = tmp_path / 'template.json'
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir'] = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    template_path.write_text(json.dumps(fixture))

    employee_path = tmp_path / 'employee.json'
    employee_path.write_text(json.dumps(EMPLOYEE))

    out = tmp_path / 'out.pdf'

    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(template_path),
        '--employee', str(employee_path),
        '--out', str(out),
    ])

    assert out.exists() and out.stat().st_size > 0
    doc = fitz.open(str(out))
    assert doc.page_count == 2


def test_renderer_embeds_svg_bg_as_vector(otech_dir, tmp_path):
    template_path = tmp_path / 'template.json'
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    template_path.write_text(json.dumps(fixture))
    (tmp_path / 'employee.json').write_text(json.dumps(EMPLOYEE))

    out = tmp_path / 'out.pdf'
    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(template_path),
        '--employee', str(tmp_path / 'employee.json'),
        '--out', str(out),
    ])

    doc = fitz.open(str(out))
    # Both pages should have at least one vector drawing from the SVG.
    for i, page in enumerate(doc):
        drawings = page.get_drawings()
        assert len(drawings) > 100, (
            f"page {i} has only {len(drawings)} drawings, "
            f"expected >100 (source SVG has ~1900 paths each)"
        )


def test_dynamic_text_is_real_pdf_text(otech_dir, tmp_path):
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    (tmp_path / 'tpl.json').write_text(json.dumps(fixture))
    (tmp_path / 'emp.json').write_text(json.dumps(EMPLOYEE))
    out = tmp_path / 'out.pdf'

    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(tmp_path / 'tpl.json'),
        '--employee', str(tmp_path / 'emp.json'),
        '--out', str(out),
    ])

    doc = fitz.open(str(out))
    back = doc[1]
    page_text = back.get_text()
    assert 'Muhammed Ali' in page_text, page_text
    assert 'Product Manager' in page_text, page_text
    assert '+968 9771 2345' in page_text, page_text
    assert 'muhammed.ali@otech.om' in page_text, page_text


def test_fonts_are_embedded(otech_dir, tmp_path):
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    (tmp_path / 'tpl.json').write_text(json.dumps(fixture))
    (tmp_path / 'emp.json').write_text(json.dumps(EMPLOYEE))
    out = tmp_path / 'out.pdf'

    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(tmp_path / 'tpl.json'),
        '--employee', str(tmp_path / 'emp.json'),
        '--out', str(out),
    ])

    doc = fitz.open(str(out))
    fonts = []
    for page in doc:
        fonts.extend(page.get_fonts(full=True))
    families = {f[3].split('+')[-1].lower() for f in fonts}
    assert any('lato' in f for f in families), f"Lato not embedded: {families}"


def test_text_baselines_match_source_pdf(otech_dir, tmp_path):
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    (tmp_path / 'tpl.json').write_text(json.dumps(fixture))
    placeholder = {
        'id': 'sample',
        'name_en':     'Muhammed Ali',
        'position_en': 'Product Manager',
        'mobile':      'M +971 50 789 4563',
        'email':       'E muhammed.ali@otech.om',
        'address_en':  'H8JG+52V, Muscat, Oman',
        'website':     'www.otech.om',
    }
    (tmp_path / 'emp.json').write_text(json.dumps(placeholder))
    out = tmp_path / 'out.pdf'
    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(tmp_path / 'tpl.json'),
        '--employee', str(tmp_path / 'emp.json'),
        '--out', str(out),
    ])

    src = fitz.open(os.path.join(otech_dir, 'source.pdf'))
    new = fitz.open(str(out))

    def first_bbox(page, needle):
        for b in page.get_text('dict')['blocks']:
            if b.get('type') != 0: continue
            for l in b['lines']:
                for s in l['spans']:
                    if needle in s['text']:
                        return s['bbox']
        return None

    for needle in ['Muhammed Ali', 'Product Manager', 'muhammed.ali@otech.om']:
        sb = first_bbox(src[1], needle)
        nb = first_bbox(new[1], needle)
        assert sb and nb, f"missing bbox for {needle}: src={sb} new={nb}"
        assert abs(sb[1] - nb[1]) < 1.5, (
            f"{needle} y drift {sb[1]} vs {nb[1]} = {sb[1]-nb[1]:+.2f}pt"
        )
        assert abs(sb[0] - nb[0]) < 1.0, (
            f"{needle} x drift {sb[0]} vs {nb[0]} = {sb[0]-nb[0]:+.2f}pt"
        )
