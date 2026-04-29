"""
Cache invalidation tests for CardRenderer::invalidateForCompany
and CardRenderer::invalidateForEmployee.

These tests shell to the live VPS via SSH, so they require an authenticated
SSH key to root@147.93.20.54. If the SSH call fails, the tests are skipped
rather than failing the suite, so CI stays green.

Manual run:
    python3 -m pytest tests/python/test_invalidation.py -v
"""
import subprocess
import pytest

VPS = 'root@147.93.20.54'
PHP = '/www/server/php/83/bin/php -r'
CARDIFY = '/www/wwwroot/cardify.om'


def _can_ssh() -> bool:
    """Quick connectivity check without running any PHP."""
    try:
        subprocess.check_output(
            ['ssh', '-o', 'ConnectTimeout=5', '-o', 'BatchMode=yes',
             VPS, 'echo ok'],
            timeout=10,
        )
        return True
    except Exception:
        return False


def _exec_php(code: str) -> str:
    """
    Run a PHP one-liner on the VPS and return stdout stripped.
    Quotes are handled by passing the code as a single argv element.
    """
    result = subprocess.check_output(
        ['ssh', VPS, f'{PHP} \'{code.strip()}\''],
        timeout=30,
        stderr=subprocess.DEVNULL,
    )
    return result.decode().strip()


@pytest.fixture(scope='module', autouse=True)
def require_ssh():
    if not _can_ssh():
        pytest.skip('VPS SSH not reachable from this environment; skipping invalidation tests')


def test_invalidate_for_company_sweeps_pdf_vector():
    """
    CardRenderer::invalidateForCompany() must delete all *.pdf files in
    tmp/pdf-vector/. We pre-create a sentinel file, call the method, then
    assert the sentinel is gone.
    """
    code = f"""
        require "{CARDIFY}/config.php";
        require "{CARDIFY}/includes/CardRenderer.php";
        $cd = BASE_DIR . "/tmp/pdf-vector";
        @mkdir($cd, 0775, true);
        $sentinel = $cd . "/sentinel-test.pdf";
        file_put_contents($sentinel, str_repeat("x", 2048));
        CardRenderer::invalidateForCompany("otech7010-rfq-2026-odp-omandatapark", "test");
        echo file_exists($sentinel) ? "still-there" : "swept";
    """
    out = _exec_php(code)
    assert 'swept' in out, f'Expected "swept", got: {out!r}'


def test_invalidate_for_employee_sweeps_pdf_vector():
    """
    CardRenderer::invalidateForEmployee() must also sweep tmp/pdf-vector/.
    Same sentinel approach.
    """
    code = f"""
        require "{CARDIFY}/config.php";
        require "{CARDIFY}/includes/CardRenderer.php";
        $cd = BASE_DIR . "/tmp/pdf-vector";
        @mkdir($cd, 0775, true);
        $sentinel = $cd . "/sentinel-emp-test.pdf";
        file_put_contents($sentinel, str_repeat("y", 2048));
        CardRenderer::invalidateForEmployee("muhammed.ali", "test");
        echo file_exists($sentinel) ? "still-there" : "swept";
    """
    out = _exec_php(code)
    assert 'swept' in out, f'Expected "swept", got: {out!r}'
