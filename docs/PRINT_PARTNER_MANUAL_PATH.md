# Manual path: printer signs up and operates a client company

Do this on staging after migration 155. Do not use the live BHD shop.

1. Open `/print-shops/register` or `/partners`.
2. Register a shop in any country. Sign in at `/login` with that email.
3. Open Print shop → Clients → Create client company. Name it, optional slug.
4. You land on that company's Employees page (the existing company admin).
5. Add two people (Add employee, or Import CSV).
6. Brand → Templates: lock one template. Generated → Batch generate.
7. Cards exist under that one template. Set print prices on Print shop → Client pricing or Settings. Those prices are the shop's, not a new Cardify list.

BHD Printing on `/print-shops` and published Oman prices (OMR 5.000 / 6.000 / 15.000 per 100, NFC 10.000/card) stay as they are.

Still blocked until Master applies `docs/print-partner-nginx-rewrites.conf` and runs migration 155: `/print-shops/register` may 404 if nginx has no `error_page 404 /404.php`. `/partners` works from PHP via `router.php` after deploy.
