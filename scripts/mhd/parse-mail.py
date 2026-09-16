#!/usr/bin/env python3
"""Read one RFC 822 message file and print what the purchase-order intake needs.

MIME is parsed with the Python standard library rather than by hand: MHD reply
with Outlook, whose subjects arrive RFC 2047 encoded (base64), whose bodies are
nested multipart/related, and whose attachments carry names in either the
Content-Type or the Content-Disposition header. A hand-rolled PHP parser got
every one of those wrong.

Output is a single JSON object on stdout:
  {message_id, subject, from, date, body, attachments: [{name, size, b64}]}
Only PDF attachments are returned, and only up to the size cap, because the
caller wants the purchase order and nothing else.
"""
import base64
import email
import email.policy
import json
import sys

MAX_ATTACHMENT = 20 * 1024 * 1024


def body_text(msg):
    try:
        part = msg.get_body(preferencelist=('plain', 'html'))
    except Exception:
        part = None
    if part is None:
        return ''
    try:
        text = part.get_content()
    except Exception:
        payload = part.get_payload(decode=True) or b''
        text = payload.decode('utf-8', 'replace')
    if part.get_content_type() == 'text/html':
        import re
        text = re.sub(r'<[^>]+>', ' ', text)
    return text[:200000]


def main(path):
    with open(path, 'rb') as fh:
        msg = email.message_from_binary_file(fh, policy=email.policy.default)

    attachments = []
    for part in msg.walk():
        if part.get_content_maintype() == 'multipart':
            continue
        name = part.get_filename()
        ctype = part.get_content_type()
        if not name and ctype != 'application/pdf':
            continue
        if name and not name.lower().endswith('.pdf') and ctype != 'application/pdf':
            continue
        data = part.get_payload(decode=True) or b''
        if not data or len(data) > MAX_ATTACHMENT:
            continue
        attachments.append({
            'name': name or 'attachment.pdf',
            'size': len(data),
            'b64': base64.b64encode(data).decode('ascii'),
        })

    out = {
        'message_id': str(msg.get('Message-ID') or ''),
        'subject': str(msg.get('Subject') or ''),
        'from': str(msg.get('From') or ''),
        'to': str(msg.get('To') or ''),
        'date': str(msg.get('Date') or ''),
        'body': body_text(msg),
        'attachments': attachments,
    }
    json.dump(out, sys.stdout, ensure_ascii=False)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.stderr.write('usage: parse-mail.py <message-file>\n')
        sys.exit(2)
    main(sys.argv[1])
