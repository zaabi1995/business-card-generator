#!/usr/bin/env bash
# Live half of the r6-62 invariant: every retired blog slug must answer 301 to
# its live successor, in BOTH language trees, and every successor must answer
# 200. Run against the deployed site, not a build.
#
#   bash scripts/verify_blog_redirects.sh [https://cardify.om]
#
# Cloudflare answers a bare curl UA with 403, which would make every check
# look like a failure (or, worse, a pass if you only grep for "not 404"), so
# send a browser UA and print the code we actually saw.
set -uo pipefail
BASE="${1:-https://cardify.om}"
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'

MAP=$(php -r '
require "includes/BlogSlugRedirects.php";
foreach (BlogSlugRedirects::all() as $o => $n) { echo "$o $n\n"; }
')

fails=0
while read -r old new; do
  [ -z "$old" ] && continue

  # EN: one hop, straight to the successor.
  url="$BASE/blog/$old"
  code=$(curl -s -o /dev/null -A "$UA" -w '%{http_code}' "$url")
  loc=$(curl -s -o /dev/null -A "$UA" -D - "$url" 2>/dev/null | tr -d '\r' | awk 'tolower($1)=="location:"{print $2}')
  want="$BASE/blog/$new"
  if [ "$code" = "301" ] && [ "$loc" = "$want" ]; then
    echo "  OK   /blog/$old -> $new"
  else
    echo "  FAIL /blog/$old code=$code location=${loc:-none} want=301 $want"
    fails=$((fails + 1))
  fi

  # AR: nginx retired /ar/blog wholesale, so this is TWO hops by design,
  # /ar/blog/<old> -> /blog/<old> -> /blog/<new>. Assert the whole chain ends
  # on the live post rather than pretending the first hop is the fix: the
  # earlier version of this script asserted an AR target PHP can never emit
  # and reported 12 failures against working redirects.
  achain=$(curl -s -o /dev/null -A "$UA" -L -w '%{url_effective} %{http_code}' "$BASE/ar/blog/$old")
  if [ "$achain" = "$BASE/blog/$new 200" ]; then
    echo "  OK   /ar/blog/$old ->(2 hops) $new"
  else
    echo "  FAIL /ar/blog/$old chain=[$achain] want=[$BASE/blog/$new 200]"
    fails=$((fails + 1))
  fi

  tcode=$(curl -s -o /dev/null -A "$UA" -w '%{http_code}' "$BASE/blog/$new")
  if [ "$tcode" != "200" ]; then
    echo "  FAIL target /blog/$new code=$tcode want=200"
    fails=$((fails + 1))
  fi
done <<< "$MAP"

# A retired slug must not be reachable from any live post either: the 301 is
# for the outside world, the anchors themselves were rewritten.
echo "== in-content anchors"
for s in $(echo "$MAP" | awk '{print $1}'); do
  hits=$(curl -s -A "$UA" "$BASE/sitemap-blog.xml" \
    | grep -oE '<loc>[^<]+</loc>' | sed 's|</\?loc>||g' \
    | while read -r u; do curl -s -A "$UA" "$u" | grep -oE "href=\"[^\"]*/blog/$s\"" ; done | wc -l | tr -d ' ')
  if [ "$hits" != "0" ]; then
    echo "  FAIL $s is still linked $hits time(s) from a live post"
    fails=$((fails + 1))
  fi
done

echo
if [ "$fails" -eq 0 ]; then echo "PASS: blog redirect map is live and no live post links a retired slug"; else echo "FAIL: $fails problem(s)"; fi
exit $([ "$fails" -eq 0 ] && echo 0 || echo 1)
