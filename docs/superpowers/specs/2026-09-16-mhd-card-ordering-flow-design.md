# MHD card ordering, approval and fulfilment

Design, 16 September 2026.

## Goal

Turn the MHD card portal from "employee submits, someone emails BHD" into one tracked
flow: employee submits, the division approves in one tap, BHD quotes, MHD replies with a
purchase order, BHD issues the documents and prints, the delivery note is signed in one
tap. Every step is one click. Nobody has to learn a new system.

## What already exists

- `portal.php` collects the card and renders a live preview per division.
- `AdminApprovalToken` mints single-use, prefetch-safe magic links, already used for
  one-tap approvals by email.
- `admin/one-tap-approve.php` consumes those links and runs `approveRequestChain()`.
- `MhdMailer` sends as sales@bhdoman.com through the M365 smarthost, the only path that
  reaches Trend-Micro-protected MHD.
- `ERPSync` converts a BHD-ERP quote into an invoice against a payment reference and
  already accepts `po` as the method.
- BHD-ERP issues quotes, invoices and delivery notes with the ePOD signature flow.

The work is a state machine over these parts, plus purchase-order intake, plus a console.

## The flow

| # | Step | Who acts | What they do |
|---|---|---|---|
| 1 | Submit | Employee | Picks division, sees where it will go, fills name, title, mobile, sees the card, submits |
| 2 | Approve | Division mailbox | One tap in an email, Approve or Reject |
| 3 | Quote | BHD-ERP, automatic | Raises a real quotation, emails it |
| 4 | Purchase order | Division mailbox | Replies to that email with the PO attached |
| 5 | Documents | BHD-ERP, automatic | Quote becomes invoice, delivery note issued, all signed by BHD |
| 6 | Production | Cardify, automatic | Print-ready PDF to BHD production on WhatsApp, job on the kanban |
| 7 | Receipt | Division mailbox | One tap on dispatch signs the delivery note |

### Step 1, telling the employee where it goes

The moment a division is picked, the form says who will receive the request, by name and
address: "This goes to Technology & Communications, tech.comm@mhd.co.om, for approval."
The same line repeats on the review step and in the confirmation the employee gets.

Nobody should have to ask who is holding up their card. If a division has a head on CC,
that is shown too.

### Status model

`card_requests.status` gains the fulfilment states. Existing values keep their meaning.

```
submitted -> approved -> quoted -> po_received -> in_production -> dispatched -> delivered
                 \-> rejected
```

Every transition writes a row to a new `card_request_events` table: state, actor, time,
and the evidence (token used, PO number, ERP document id). That table is the audit trail
and it is what the console reads.

### Step 2, the approval email

To the division mailbox, the division head on CC. Contains the rendered card front and
back as inline images, the employee's details as text, and exactly two buttons: Approve
and Reject. No login. The token is scoped to one company and one request, single use.

Rejection asks for one line of reason and emails it back to the employee.

### Step 3, the quotation

On approval, BHD-ERP raises a quotation against the division's own client record, priced
from the rate table below. The email goes to the division mailbox from
sales@bhdoman.com with the quotation PDF attached, and one sentence: reply to this email
with your purchase order attached.

The subject carries an opaque job tag, `[MHD-<short-ref>]`, so the reply can be matched
without relying on threading headers that Outlook may rewrite.

### Step 4, purchase-order intake

A poller reads the sales@bhdoman.com mailbox over IMAP every two minutes. For each unseen
message it:

1. Matches the job by the `[MHD-<short-ref>]` tag in the subject, falling back to
   `In-Reply-To` and then to the sender's open job for that division.
2. Takes the first PDF attachment as the purchase order.
3. Extracts the PO number with `\b41\d{8}\b`. MHD's numbers are ten digits beginning 41.
4. Files the PDF against the ERP quote and records the number.
5. Moves the job to `po_received`, exactly once. A second reply on the same job is
   filed as an additional document and does not re-trigger anything.

If a reply arrives with no PDF, the job stays at `quoted` and BHD sales gets a note. No
guessing.

### Step 5, the documents

`ERPSync` converts the quote to an invoice with method `po` and the PO number as the
reference, then issues the delivery note. All three PDFs carry Ali's signature and the
company stamp by default. One email to the division mailbox with the three attached.

### Step 6, production

Cardify pushes the print-ready PDF to BHD production on WhatsApp through the existing
Dardasha path, and the manufacturing order appears on the ERP kanban. The artwork is the
same vector PDF the portal already renders, at the division's correct trim.

### Step 7, receipt

When BHD dispatches, one email: your cards are on the way, tap to confirm receipt. One
tap on a scoped link signs the delivery note and closes the job at `delivered`.

The signature button sits at dispatch, not at the purchase-order step, so the click means
the cards actually arrived. MHD still receives the delivery note for their records at
step 5.

## Divisions and approvers

Taken from 3,121 card threads and 365 days of invoices. See
`~/claude/research/mhd-division-approvers-2026-09-16/`.

| Division | Approver mailbox | CC | Live? |
|---|---|---|---|
| ITICS | iticsceooffice@mhd.co.om | bipin.k@mhd.co.om | yes, 14 orders |
| Logistics | to be named by MHD | to be named | yes, 10 orders |
| Infrastructure & Building Systems | ibs@mhd.co.om | himanshu.p@mhd.co.om | yes, 7 orders |
| Technology & Communications | tech.comm@mhd.co.om | rajanikanth.r@mhd.co.om | yes, 15 orders |
| Office Products | opd@mhd.co.om | sadasivam@mhd.co.om | yes, 8 orders |
| Consumer | aliakbar@mhd.co.om | - | yes, 7 orders |
| IPD | ipd@mhd.co.om | devanand.v@mhd.co.om | threads only |
| Building Materials | bmdsales@mhd.co.om | devanand.v@mhd.co.om | threads only |
| Healthcare | healthcare@mhd.co.om | anandha.k@mhd.co.om | threads only |
| EEP | eep@mhd.co.om | gokul.p@mhd.co.om | hidden today |
| **Automotive** | **none** | **none** | **switch off** |

Automotive has never ordered a card from BHD. Its division comes off the picker.

`departments` gains `head_email` for the CC. `responsible_email` stays the approver.

## Pricing

**Standard card only.** Art 300 GSM, matte, the stock MHD buys on almost every order. No
spot UV, no FBB 400, no foil, no die cut. Those are real products BHD sells, but they are
quoted by hand and they do not belong in a self-service flow.

Flat rate, matching what BHD already bills MHD.

| Quantity | Ex-VAT | Inc 5% VAT |
|---|---|---|
| 100 | 3.000 | 3.150 |
| 200, the standard staff lot | 6.000 | 6.300 |
| 300 | 9.000 | 9.450 |
| 400 | 12.000 | 12.600 |

Rate held in `departments.card_unit_price`, defaulting to 0.030. The portal offers only
these four quantities. Anything else, and any non-standard stock, stays a phone call to
BHD sales exactly as it is today. The quotation line reads "Business Card (Art 300 GSM,
Matte)" so the invoice matches the historic wording.

## The console

One page, `mhd.cardify.om/admin/orders`, for BHD staff and for MHD. It answers "where is
my card" without anyone asking.

Each job shows one row: employee, division, current state, and the date of the last
change. Opening a row shows everything in one place:

- the card front and back as rendered
- who approved it and when
- the quotation, the purchase order PDF, the invoice, the delivery note
- the signature on the delivery note
- the full event history

MHD signs in the way they already do, by one-time code to their email. No password. Any
address on the tenant's domain that has been used as an approver or a CC can sign in and
sees only their own division. `iticsceooffice@` and BHD staff see every division.

That is a new `viewer_scope` on the tenant user: `division` or `all`.

## Data model

| Change | Why |
|---|---|
| `card_requests.status` gains the fulfilment states | the state machine |
| `card_requests.job_ref` | the `[MHD-<ref>]` tag used to match the PO reply |
| `card_requests.erp_quote_id`, `erp_invoice_id`, `erp_dn_id`, `po_number`, `po_file` | the document chain |
| new `card_request_events` | audit trail and console history |
| `departments.head_email`, `departments.card_unit_price` | CC and rate |
| `users.viewer_scope` | who sees which division |

## Failure handling

- The PO poller is idempotent on message id. A replay files nothing twice.
- Every automatic email is logged in `email_logs`, as `MhdMailer` already does. A failed
  send leaves the job in its current state and alerts BHD sales, it never advances.
- ERP calls that fail leave the job at the previous state with the error on the event row.
  A job never reaches `po_received` without a stored PO.
- A card request with no division is refused at submit, as today.

## Testing

- Unit: the state machine refuses every transition that is not in the diagram; the PO
  number extractor against the 35 real PO formats found in the mail; the rate table.
- Integration: a full run through all seven states against a test division routed to
  ali@bhd.om, with the email domain temporarily widened, then reverted. This is the
  method already used to verify the current portal.
- The PO poller is tested against saved copies of real MHD purchase-order emails,
  including the scanner output named `SKM_C....pdf` and a reply with no attachment.

## Out of scope

- Reprints and bulk orders for a whole division. One employee, one card, for now.
- Non-standard stock: spot UV, FBB 400 GSM, foil, die cut, embossing. Quoted by hand.
- Paying the invoice online. MHD settles on account.
- Anything for Automotive.
- Polycon, an MHD entity that buys cards and has no Cardify division. Flagged, not built.

## Open items for MHD

1. Who approves for Logistics? They are the second-biggest buyer and have no named
   contact in BHD's mail.
2. Confirm the four division heads marked medium or low confidence in the research.

## Cleanups to do alongside

- Remove invoice 3419, an OMR 15.000 Cardify test sync sitting in the live ledger.
- Merge the two live ERP clients for Technology & Communications.
