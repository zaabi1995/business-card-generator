#!/usr/bin/env python3
"""Extract vector logos from the 2025 government identity guide, without tracing."""
import argparse, hashlib, io, json, re, xml.etree.ElementTree as ET
from pathlib import Path
import fitz
from PIL import Image

# PDF page, spread side, row, English name, Arabic name. Names transcribed from the guide.
GROUPS = {
 (24,'left'): [('Council of Ministers','مجلس الوزراء'),('Diwan of Royal Court','ديوان البلاط السلطاني'),('The Royal Office','المكتب السلطاني')],
 (24,'right'): [('Office of the Deputy Prime Minister for Council of Ministers','مكتب نائب رئيس الوزراء لشؤون مجلس الوزراء'),('Office of Deputy Prime Minister for International Relations and Cooperation Affairs and Personal Representative of His Majesty the Sultan','مكتب نائب رئيس الوزراء لشؤون العلاقات والتعاون الدولي والممثل الخاص لجلالة السلطان'),('Office of the Special Envoy of His Majesty the Sultan','مكتب المبعوث الخاص لجلالة السلطان')],
 (25,'right'): [('The Supreme Judicial Council','المجلس الأعلى للقضاء'),('Royal Court Affairs','شؤون البلاط السلطاني'),('The Private Office','المكتب الخاص'),('The General Secretariat of the Council of Ministers','الأمانة العامة لمجلس الوزراء')],
 (26,'left'): [('The General Secretariat for the National Celebrations','الأمانة العامة للاحتفالات الوطنية'),('Oman Vision 2040 Implementation Follow-Up Unit','وحدة متابعة تنفيذ رؤية عُمان 2040'),('Ministry of Culture, Sports and Youth','وزارة الثقافة والرياضة والشباب')],
 (26,'right'): [('Ministry of Interior','وزارة الداخلية'),('Ministry of Foreign Affairs','وزارة الخارجية'),('Ministry of Finance','وزارة المالية')],
 (27,'left'): [('Ministry of Heritage and Tourism','وزارة التراث والسياحة'),('Ministry of Agriculture, Fisheries and Water Resources','وزارة الثروة الزراعية والسمكية وموارد المياه'),('Ministry of Housing and Urban Planning','وزارة الإسكان والتخطيط العمراني')],
 (27,'right'): [('Ministry of Justice and Legal Affairs','وزارة العدل والشؤون القانونية'),('Ministry of Labour','وزارة العمل'),('Ministry of Transport, Communications and Information Technology','وزارة النقل والاتصالات وتقنية المعلومات')],
 (28,'left'): [('Ministry of Higher Education, Research and Innovation','وزارة التعليم العالي والبحث العلمي والابتكار'),('Ministry of Health','وزارة الصحة'),('Ministry of Social Development','وزارة التنمية الاجتماعية')],
 (28,'right'): [('Ministry of Economy','وزارة الاقتصاد'),('Ministry of Information','وزارة الإعلام'),('Ministry of Energy and Minerals','وزارة الطاقة والمعادن'),('Ministry of Commerce Industry and Investment Promotion','وزارة التجارة والصناعة وترويج الاستثمار')],
 (29,'left'): [('Muscat Governorate','محافظة مسقط'),('Dhofar Governorate','محافظة ظفار'),('Musandam Governorate','محافظة مسندم')],
 (29,'right'): [('Ministry of Education','وزارة التربية والتعليم'),('Ministry of Endowments and Religious Affairs','وزارة الأوقاف والشؤون الدينية'),('Ministry of Defence','وزارة الدفاع')],
 (30,'left'): [('Al Sharqiyah South Governorate','محافظة جنوب الشرقية'),('Al Sharqiyah North Governorate','محافظة شمال الشرقية'),('Al Wusta Governorate','محافظة الوسطى'),('Al Dhahirah Governorate','محافظة الظاهرة')],
 (30,'right'): [('Al Buraimi Governorate','محافظة البريمي'),('Al Dakhiliyah Governorate','محافظة الداخلية'),('Al Batinah North Governorate','محافظة شمال الباطنة'),('Al Batinah South Governorate','محافظة جنوب الباطنة')],
 (31,'left'): [('Tax Authority','جهاز الضرائب'),('Oman Investment Authority','جهاز الاستثمار العُماني'),('National Centre for Statistics and Information','المركز الوطني للإحصاء والمعلومات')],
 (31,'right'): [('Board of Governors of the Central Bank of Oman','مجلس محافظي البنك المركزي العُماني'),('Oman Medical Speciality Board','المجلس العُماني للاختصاصات الطبية'),('State Financial and Administrative Audit Authority','جهاز الرقابة المالية والإدارية للدولة')],
}
BANDS={24:[(320,462),(480,610),(625,773)],25:[(160,290),(345,455),(497,613),(650,773)],26:[(335,445),(485,604),(640,755)],27:[(160,285),(315,442),(470,596)],28:[(155,288),(310,435),(455,585),(608,753)],29:[(330,434),(490,601),(638,759)],30:[(171,287),(330,437),(480,586),(626,755)],31:[(295,440),(462,598),(614,755)]}
NS='http://www.w3.org/2000/svg'
ET.register_namespace('',NS);ET.register_namespace('xlink','http://www.w3.org/1999/xlink')

def extract(doc, page_no, region):
    page=doc[page_no-1]
    pix=page.get_pixmap(matrix=fitz.Matrix(2,2),clip=region,alpha=True)
    alpha=Image.open(io.BytesIO(pix.tobytes('png'))).convert('RGBA').getchannel('A')
    box=alpha.getbbox()
    if box is None: raise ValueError('Empty extraction')
    crop=fitz.Rect(region.x0+box[0]/2-5,region.y0+box[1]/2-5,region.x0+box[2]/2+5,region.y0+box[3]/2+5)
    out=fitz.open();out.insert_pdf(doc,from_page=page_no-1,to_page=page_no-1)
    p=out[0];w,h=p.rect.width,p.rect.height
    for r in [fitz.Rect(0,0,w,crop.y0),fitz.Rect(0,crop.y1,w,h),fitz.Rect(0,crop.y0,crop.x0,crop.y1),fitz.Rect(crop.x1,crop.y0,w,crop.y1)]:p.add_redact_annot(r,fill=False)
    p.apply_redactions(images=1,graphics=1,text=0);p.set_cropbox(crop)
    svg=p.get_svg_image(text_as_path=True)
    if '<image' in svg or '<text' in svg:raise ValueError('Expected pure vector outlines')
    return svg, list(crop)

def monochrome(svg,tone):
    root=ET.fromstring(svg)
    for e in root.iter():
        # Default SVG fill is black. Apply it to all visible shape/use nodes.
        if e.tag.split('}')[-1] in ['path','use','rect','circle','ellipse','polygon','polyline','g']:
            if e.get('fill')!='none': e.set('fill',tone)
            if e.get('stroke') and e.get('stroke')!='none':e.set('stroke',tone)
    return ET.tostring(root,encoding='unicode')

def svg_pdf(svg):
    vector = fitz.open(stream=svg.encode(), filetype='svg')
    return fitz.open(stream=vector.convert_to_pdf(), filetype='pdf')

def tight_svg(svg, resolution=8192):
    """Trim to painted bounds, retaining every source path and transform."""
    root = ET.fromstring(svg)
    old = [float(n) for n in root.attrib['viewBox'].split()]
    pdf = svg_pdf(svg)
    page = pdf[0]
    scale = resolution / max(page.rect.width, page.rect.height)
    pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=True)
    alpha = Image.open(io.BytesIO(pix.tobytes('png'))).getchannel('A')
    box = alpha.getbbox()
    if not box:
        raise ValueError('Empty vector artwork')
    # One high-resolution pixel protects anti-aliased tips. At a 2048 export
    # this is at most 0.25 px, and the raster exports are trimmed separately.
    left = max(0, box[0] - 1) / scale
    top = max(0, box[1] - 1) / scale
    right = min(page.rect.width, (box[2] + 1) / scale)
    bottom = min(page.rect.height, (box[3] + 1) / scale)
    bounds = [old[0] + left, old[1] + top, right-left, bottom-top]
    number = lambda value: f'{value:.8f}'.rstrip('0').rstrip('.')
    root.set('viewBox', ' '.join(number(n) for n in bounds))
    root.set('width', number(bounds[2]))
    root.set('height', number(bounds[3]))
    return ET.tostring(root, encoding='unicode'), bounds

def write_svg_assets(svg, folder, key, layout):
    assets = {}
    for tone, color in [('normal', None), ('black', '#000000'), ('white', '#ffffff')]:
        name = f'{layout}-{tone}'
        text = svg if color is None else monochrome(svg, color)
        (folder / f'{name}.svg').write_text(text)
        pdf = svg_pdf(text)
        pdf.save(folder / f'{name}.pdf', garbage=4, deflate=True)
        files = {'svg': f'{key}/{name}.svg', 'pdf': f'{key}/{name}.pdf'}
        for size in ([512, 1024, 2048] if tone == 'normal' else [2048]):
            scale = size / max(pdf[0].rect.width, pdf[0].rect.height)
            pix = pdf[0].get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=True)
            image = Image.open(io.BytesIO(pix.tobytes('png'))).convert('RGBA')
            image = image.crop(image.getchannel('A').getbbox())
            image.save(folder / f'{name}-{size}.png', optimize=True)
            files[f'png_{size}'] = f'{key}/{name}-{size}.png'
            if size == (1024 if tone == 'normal' else 2048):
                image.save(folder / f'{name}.webp', 'WEBP', lossless=True)
                files['webp'] = f'{key}/{name}.webp'
        assets[tone] = files
    return assets

def write_layout(doc, page, region, folder, key, layout):
    svg, crop = extract(doc, page, fitz.Rect(region))
    svg, bounds = tight_svg(svg)
    return {'crop': crop, 'canvas_viewbox': bounds,
            'assets': write_svg_assets(svg, folder, key, layout), 'pdf_page': page}

def add_supplemental(doc, dest, manifest, companies):
    key='sultanate-of-oman';folder=dest/key;folder.mkdir(exist_ok=True)
    matches=[c for c in companies if c['name_en'].strip().lower()=='sultanate of oman']
    if len(matches)>1:raise ValueError('Ambiguous state entity')
    state={'key':key,'name_en':'Sultanate of Oman','name_ar':'سلطنة عُمان','company_id':matches[0]['id'] if matches else None,'slug':matches[0]['slug'] if matches else None,'pdf_page':9,'printed_pages':[16,17],'layouts':{}}
    state['layouts']['bilingual']=write_layout(doc,9,[80,280,540,555],folder,key,'bilingual')
    state['layouts']['arabic']=write_layout(doc,9,[710,275,1040,560],folder,key,'arabic')
    manifest['entities'].append(state)
    international={
        'ministry-of-foreign-affairs':[670,270,868,350],
        'ministry-of-health':[650,410,868,505],
        'oman-investment-authority':[668,558,868,665],
        'diwan-of-royal-court':[930,247,1110,355],
        'the-general-secretariat-of-the-council-of-ministers':[890,389,1110,503],
        'the-royal-office':[920,545,1110,665],
    }
    for row in manifest['entities']:
        if row['key'] in international:
            row['layouts']['international']=write_layout(doc,33,international[row['key']],dest/row['key'],row['key'],'international')
            row['layouts']['international']['usage']='Outside the Sultanate of Oman only'
    return manifest

def main():
    ap=argparse.ArgumentParser();ap.add_argument('pdf');ap.add_argument('output');ap.add_argument('--companies');a=ap.parse_args()
    source=Path(a.pdf);dest=Path(a.output);dest.mkdir(parents=True,exist_ok=True);doc=fitz.open(source)
    companies=json.loads(Path(a.companies).read_text()) if a.companies else []
    norm=lambda s:re.sub(r'[^a-z0-9]','',s.lower())
    manifest={'source_file':source.name,'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'edition':2025,'entities':[]}
    for (page,side),rows in GROUPS.items():
        for i,(en,ar) in enumerate(rows):
            key=re.sub(r'[^a-z0-9]+','-',en.lower()).strip('-'); folder=dest/key;folder.mkdir(exist_ok=True)
            matches=[c for c in companies if norm(c['name_en'])==norm(en)]
            if len(matches)>1:raise ValueError('Ambiguous company '+en)
            row={'key':key,'name_en':en,'name_ar':ar,'company_id':matches[0]['id'] if matches else None,'slug':matches[0]['slug'] if matches else None,'pdf_page':page,'printed_pages':[page*2-2,page*2-1],'layouts':{}}
            y0,y1=BANDS[page][i]
            for layout,xs in [('bilingual',(25,350) if side=='left' else (620,944)),('arabic',(352,590) if side=='left' else (945,1165))]:
                ly0,ly1=y0,y1
                if page==28 and i==2: ly0,ly1=(480,575) if layout=='bilingual' else (465,575)
                if page==28 and i==3: ly0,ly1=(645,745) if layout=='bilingual' else (625,745)
                if page==31:
                    ly0,ly1=([(350,434),(505,585),(655,745)] if layout=='bilingual' else [(305,434),(475,585),(625,745)])[i]
                row['layouts'][layout]=write_layout(doc,page,[xs[0],ly0,xs[1],ly1],folder,key,layout)
            manifest['entities'].append(row)
            print(en, 'existing='+str(row['company_id']),flush=True)
    manifest=add_supplemental(doc,dest,manifest,companies)
    (dest/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2))
    print('Entities',len(manifest['entities']))
if __name__=='__main__':main()
