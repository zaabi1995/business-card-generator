# QA crawlers

Two Playwright crawlers that run against a live site, used to verify a deploy
beyond what the deploy script's HTTP smoke can see.

    node scripts/qa/responsive-crawl.mjs     # 38 routes x 4 viewports
    node scripts/qa/csp-crawl.mjs            # 40 routes, one desktop viewport

Override the target with `BASE_URL=https://staging...`.

`responsive-crawl.mjs` reports horizontal overflow, the element causing it, HTTP
errors and console errors. It is the check that caught an 88px overflow on
/oman-business-index at 768px, and 4,409px on /print-shops/register at 320px
when a PHP tag printed as text inside a heredoc.

`csp-crawl.mjs` reports Content-Security-Policy violations, page errors, Alpine
roots that failed to initialise, and stylesheets parked on `media="print"` that
were never activated. It is the check that would have caught the CSP that
blanked Alpine on 16 of 22 pages, and it is what proved the nonce policy was
safe to enforce.

Both print `problems: 0` when the estate is clean, and every problem on its own
line otherwise. Neither writes anything.
