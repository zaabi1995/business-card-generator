#!/usr/bin/env python3
"""
Cardify outreach batch builder.

Default behaviour is to WRITE DRAFTS AND STOP. Nothing leaves the building
without --i-have-approval-from-ali on the command line, and even then the
guards below have to pass first.

Why it is built this way: 500-plus cold sends out of mail.bhd.om would put the
IP on a blocklist and take ali@bhd.om, sales@bhdoman.com and every other BHD
sender down with it. The batch cap and the ledger are the whole point.

Usage
  Build drafts for review:
    python3 send_batch.py --segment tech-press --limit 20

  Send a batch Ali has actually read and approved:
    CARDIFY_SMTP_PASS=... python3 send_batch.py --segment tech-press \
        --batch 2026-08-20-tech-press --i-have-approval-from-ali
"""

import argparse
import csv
import datetime as dt
import os
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent
CONTACTS = ROOT / "list" / "contacts.csv"
LEDGER = ROOT / "list" / "sent-ledger.csv"
BATCHES = ROOT / "batches"
TEMPLATES = ROOT / "templates"

# Hard caps. Raising these is a deliberate decision, not a default.
DAILY_CAP = 30
BATCH_CAP = 30

SEGMENT_TEMPLATE = {
    "tech-press": "01-tech-press.md",
    "scanner-creators": "02-scanner-creators.md",
    "agencies": "03-agencies.md",
    "companies": "04-companies.md",
}

PLACEHOLDER = re.compile(r"\{\{([a-z_]+)\}\}")


def load_contacts():
    if not CONTACTS.exists():
        sys.exit(f"no contacts file at {CONTACTS}")
    with CONTACTS.open(encoding="utf-8") as fh:
        return list(csv.DictReader(fh))


def load_ledger():
    """Every address ever sent to. Nobody gets a second cold email by accident."""
    if not LEDGER.exists():
        return {}, []
    with LEDGER.open(encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    return {r["email"].lower(): r for r in rows}, rows


def sent_today(rows):
    today = dt.date.today().isoformat()
    return sum(1 for r in rows if r.get("sent_on", "").startswith(today))


def extract_pitch(template_path):
    """Pull the '## Cold pitch' block: subject line plus body."""
    text = template_path.read_text(encoding="utf-8")
    m = re.search(r"^## Cold pitch\s*\n(.*?)(?=^\Z|^---\s*$)", text, re.S | re.M)
    if not m:
        sys.exit(f"no '## Cold pitch' section in {template_path.name}")
    block = m.group(1).strip()
    sm = re.search(r"^\*\*Subject:\*\*\s*(.+)$", block, re.M)
    if not sm:
        sys.exit(f"no '**Subject:**' line in {template_path.name}")
    subject = sm.group(1).strip()
    body = block[sm.end():].strip()
    return subject, body


def build(segment, limit):
    tpl_name = SEGMENT_TEMPLATE.get(segment)
    if not tpl_name:
        sys.exit(f"unknown segment {segment!r}, expected one of {sorted(SEGMENT_TEMPLATE)}")
    subject_tpl, body_tpl = extract_pitch(TEMPLATES / tpl_name)

    seen, _ = load_ledger()
    picked, skipped = [], []
    for row in load_contacts():
        if row["segment"] != segment:
            continue
        if row.get("pitchable", "").strip().lower() != "yes":
            skipped.append((row["email"], "not pitchable: " + (row.get("notes") or "")))
            continue
        if row["email"].lower() in seen:
            skipped.append((row["email"], "already in sent ledger"))
            continue
        picked.append(row)
        if len(picked) >= min(limit, BATCH_CAP):
            break

    if not picked:
        print(f"nothing to build for {segment}.")
        for e, why in skipped:
            print(f"  skipped {e}: {why}")
        return None

    stamp = f"{dt.date.today().isoformat()}-{segment}"
    outdir = BATCHES / stamp
    outdir.mkdir(parents=True, exist_ok=True)

    manifest = []
    for i, row in enumerate(picked, 1):
        fields = dict(row)
        fields.setdefault("company", row.get("outlet_or_company", ""))
        fields.setdefault("first_name", row.get("contact_name", ""))
        subject = PLACEHOLDER.sub(lambda m: fields.get(m.group(1), m.group(0)), subject_tpl)
        body = PLACEHOLDER.sub(lambda m: fields.get(m.group(1), m.group(0)), body_tpl)
        missing = sorted(set(PLACEHOLDER.findall(subject) + PLACEHOLDER.findall(body)))
        path = outdir / f"{i:02d}-{row['email'].replace('@', '_at_')}.txt"
        path.write_text(
            f"To: {row['email']}\nSubject: {subject}\n"
            f"UNFILLED: {', '.join(missing) if missing else 'none'}\n"
            f"{'-' * 70}\n{body}\n",
            encoding="utf-8",
        )
        manifest.append({"email": row["email"], "file": path.name, "missing": missing})
        flag = "NEEDS EDIT" if missing else "ready"
        print(f"  [{flag:10}] {row['email']:<38} {path.name}")
        if missing:
            print(f"               unfilled: {', '.join(missing)}")

    print(f"\n{len(manifest)} draft(s) in {outdir}")
    needs = [m for m in manifest if m["missing"]]
    if needs:
        print(f"{len(needs)} still carry unfilled placeholders. Fill them by hand.")
        print("A draft with an unfilled placeholder will NOT send.")
    print("\nNothing has been sent. Ali reviews these first.")
    return stamp


def send(segment, batch, approved):
    if not approved:
        sys.exit("refusing to send without --i-have-approval-from-ali")
    outdir = BATCHES / batch
    if not outdir.is_dir():
        sys.exit(f"no batch at {outdir}")

    seen, rows = load_ledger()
    already = sent_today(rows)
    drafts = sorted(p for p in outdir.glob("*.txt"))

    ready = []
    for p in drafts:
        head, _, body = p.read_text(encoding="utf-8").partition("-" * 70)
        to = re.search(r"^To: (.+)$", head, re.M).group(1).strip()
        subject = re.search(r"^Subject: (.+)$", head, re.M).group(1).strip()
        if PLACEHOLDER.search(subject) or PLACEHOLDER.search(body):
            print(f"  SKIP {to}: unfilled placeholder still in the draft")
            continue
        if to.lower() in seen:
            print(f"  SKIP {to}: already in sent ledger")
            continue
        ready.append((to, subject, body.strip(), p))

    if already + len(ready) > DAILY_CAP:
        allowed = max(0, DAILY_CAP - already)
        print(f"daily cap {DAILY_CAP}, already sent {already}. Trimming to {allowed}.")
        ready = ready[:allowed]
    if not ready:
        sys.exit("nothing ready to send.")

    password = os.environ.get("CARDIFY_SMTP_PASS")
    if not password:
        sys.exit("set CARDIFY_SMTP_PASS in the environment, never in this file")

    import smtplib
    from email.message import EmailMessage

    sender = "ali@bhd.om"
    host = os.environ.get("CARDIFY_SMTP_HOST", "mail.bhd.om")

    LEDGER.parent.mkdir(parents=True, exist_ok=True)
    new_ledger = not LEDGER.exists()
    with smtplib.SMTP(host, 587, timeout=30) as smtp, LEDGER.open("a", newline="", encoding="utf-8") as lf:
        smtp.starttls()
        smtp.login(sender, password)
        w = csv.DictWriter(lf, fieldnames=["email", "segment", "batch", "subject", "sent_on"])
        if new_ledger:
            w.writeheader()
        for to, subject, body, path in ready:
            msg = EmailMessage()
            msg["From"] = sender
            msg["To"] = to
            msg["Subject"] = subject
            # Plain text plus HTML. Plain-text-only bypasses the signature
            # filter and renders monospace in Outlook. Do not "simplify" this.
            msg.set_content(body)
            html = "<br>\n".join(
                line if line.strip() else "<br>" for line in body.splitlines()
            )
            msg.add_alternative(f"<div>{html}</div>", subtype="html")
            smtp.send_message(msg)
            w.writerow({
                "email": to, "segment": segment, "batch": batch,
                "subject": subject, "sent_on": dt.datetime.now().isoformat(timespec="seconds"),
            })
            lf.flush()
            print(f"  sent {to}")
    print(f"\n{len(ready)} sent, ledger updated at {LEDGER}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--segment", required=True, choices=sorted(SEGMENT_TEMPLATE))
    ap.add_argument("--limit", type=int, default=20)
    ap.add_argument("--batch")
    ap.add_argument("--i-have-approval-from-ali", action="store_true", dest="approved")
    a = ap.parse_args()
    if a.approved or a.batch:
        send(a.segment, a.batch, a.approved)
    else:
        build(a.segment, a.limit)


if __name__ == "__main__":
    main()
