#!/usr/bin/env python3
"""Re-export reviewed artwork with tight canvases and unchanged vector paths."""
import argparse
import importlib.util
import json
from pathlib import Path

spec = importlib.util.spec_from_file_location('government_identity', Path(__file__).with_name('extract-government-identity.py'))
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('source')
    parser.add_argument('output')
    args = parser.parse_args()
    source, output = Path(args.source), Path(args.output)
    output.mkdir(parents=True, exist_ok=True)
    manifest = json.loads((source / 'manifest.json').read_text())
    for entity in manifest['entities']:
        folder = output / entity['key']
        folder.mkdir(exist_ok=True)
        for name, layout in entity['layouts'].items():
            original = (source / layout['assets']['normal']['svg']).read_text()
            trimmed, bounds = identity.tight_svg(original)
            layout['canvas_viewbox'] = bounds
            layout['assets'] = identity.write_svg_assets(trimmed, folder, entity['key'], name)
        print(entity['key'], flush=True)
    manifest['asset_revision'] = 'tight-v2'
    (output / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
