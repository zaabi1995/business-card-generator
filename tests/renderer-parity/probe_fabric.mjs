// R3 transform probe.
//
// Executes the REAL browser-side geometry of generate_card_html.php by slicing
// the relevant source verbatim out of the shipped files and running it in node:
//
//   1. getTemplatePixelDims()  <- generate_card_html.php:302-319
//      (the canvas Fabric is actually sized to, generate_card_html.php:548)
//   2. the anchor derivation in CardEditor.addTextField()
//      <- assets/js/card-editor.js:704-738
//      (textAlign/originX resolution + the (x, width, originX) -> Fabric `left`
//       math: right => x+width, center => x+width/2, width<=0 => left at x)
//
// Nothing is re-implemented; both blocks are read off disk and eval'd, so a
// change to either file changes this probe's answer.
//
// Not executed: Fabric's paint step, glyph metrics and auto-shrink. This probe
// reports the ANCHOR POINT and which origin it is measured from, not an ink box.
//
// Usage: node probe_fabric.mjs <fixture.json>
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

function sliceBraceBlock(src, marker, label) {
  const start = src.indexOf(marker);
  if (start < 0) throw new Error(`${label} not found`);
  let depth = 0, end = -1;
  for (let j = src.indexOf('{', start); j < src.length; j++) {
    if (src[j] === '{') depth++;
    else if (src[j] === '}') { depth--; if (depth === 0) { end = j + 1; break; } }
  }
  return src.slice(start, end);
}

function sliceBetween(src, from, to, label) {
  const a = src.indexOf(from);
  const b = src.indexOf(to, a);
  if (a < 0 || b < 0) throw new Error(`${label} not found`);
  return src.slice(a, b);
}

// --- 1. canvas dims -------------------------------------------------------
const gch = readFileSync(resolve(here, '../../generate_card_html.php'), 'utf8');
const dimsSrc = sliceBraceBlock(gch, 'function getTemplatePixelDims(template) {',
                                'getTemplatePixelDims');
const getTemplatePixelDims = new Function(dimsSrc + '; return getTemplatePixelDims;')();

// --- 2. addTextField anchor derivation ------------------------------------
const ced = readFileSync(resolve(here, '../../assets/js/card-editor.js'), 'utf8');
const anchorSrc = sliceBetween(
  ced,
  '        // Determine text alignment and corresponding origin',
  '        // Read-only mode for preview surfaces',
  'addTextField anchor block');
// The slice ends with the anchor variables in scope; return them.
const fabricAnchor = new Function('options',
  anchorSrc + '\n return { left: _anchorLeft, originX: _anchorOriginX, textAlign: textAlign };');

// --- run ------------------------------------------------------------------
const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const converted = JSON.parse(readFileSync(process.argv[3], 'utf8')); // convertLegacyFieldPositions output

const dims = getTemplatePixelDims({ settings: fixture.settings, fields: converted });

const out = { fabric_canvas: dims, fields: {} };
for (const [key, field] of Object.entries(converted)) {
  if (key === 'qr_code') {
    // card-editor.js:1213-1216 - the QR image is placed left/top at x/y with
    // originX 'left', scaled to `size`. No alignment math.
    out.fields[key] = {
      kind: 'qr', enabled: !!field.enabled,
      left: field.x || 0, top: field.y || 0, size: field.size || 0,
    };
    continue;
  }
  // generate_card_html.php:625-626 - alignment/origin defaults.
  const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
  const originX = field.originX || (textAlign === 'center' ? 'center'
                                  : (textAlign === 'right' ? 'right' : 'left'));
  // generate_card_html.php:644 - static decorations bypass the width constraint.
  const widthForField = field.is_static ? 0 : field.width;
  const a = fabricAnchor({
    x: field.x, y: field.y, width: widthForField,
    textAlign, originX,
  });
  out.fields[key] = {
    kind: 'text',
    // generate_card_html.php:600 (`if (!field.enabled) continue`) and :606
    // (`if (field.render_in_bg) continue` - the pixels are already baked into
    // the background PNG, so Fabric must not draw them either).
    enabled: !!field.enabled && !field.render_in_bg,
    left: a.left, top: field.y || 50,  // card-editor.js:746 `top: options.y || 50`
    originX: a.originX, textAlign: a.textAlign,
    width: widthForField || 0,
  };
}
process.stdout.write(JSON.stringify(out) + '\n');
