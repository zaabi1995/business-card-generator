"""Fail closed before a full browser suite can reach public production."""
import os
import sys
from urllib.parse import urlsplit

def allowed_target(value):
    try:
        parsed = urlsplit(value)
        # This variable must identify a separately provisioned synthetic fixture.
        # Local runners are also supported. Public tenant domains are excluded.
        return (
            parsed.scheme in ('http', 'https')
            and parsed.hostname in ('localhost', '127.0.0.1', 'cardify.test', 'staging.cardify.test')
            and parsed.username is None and parsed.password is None
            and not parsed.query and not parsed.fragment
            and parsed.path in ('', '/')
        )
    except ValueError:
        return False

if __name__ == '__main__':
    if not allowed_target(os.environ.get('BASE_URL', '')):
        print('Full E2E testing requires CARDIFY_E2E_BASE_URL and an isolated synthetic fixture. Production is prohibited.', file=sys.stderr)
        sys.exit(1)
    print('Isolated E2E target accepted.')
