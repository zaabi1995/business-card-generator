import json, sys
for side in ('front','back'):
    d = json.load(open(f'tpl-{side}.json'))
    print(f"===== {side} =====")
    g = lambda x, dflt='-': dflt if x is None else x
    for k, v in d.items():
        print("{:15s} st={} bg={} x={:>4} y={:>4} w={:>4} sz={:>3} {:16s} wt{} {} al={} oX={} '{}'".format(
            k,
            str(v.get('is_static'))[0], str(v.get('render_in_bg'))[0],
            g(v.get('x')), g(v.get('y')), g(v.get('width')),
            g(v.get('fontSize')), str(v.get('fontFamily'))[:16], g(v.get('fontWeight')),
            g(v.get('fill')), g(v.get('textAlign')), g(v.get('originX')),
            (v.get('detected_text') or '')[:26]))
