import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('target_guard', pathlib.Path(__file__).resolve().parents[2] / 'scripts/check-e2e-target.py')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

class E2ETargetTests(unittest.TestCase):
    def test_rejects_production_and_confused_urls(self):
        for value in ['', 'https://cardify.om', 'https://mhd.cardify.om', 'https://erp.bhd.om', 'https://localhost.evil.test', 'https://localhost@cardify.om', 'https://user:pass@localhost', 'file:///tmp/test']:
            with self.subTest(value=value): self.assertFalse(guard.allowed_target(value))

    def test_accepts_isolated_fixture(self):
        for value in ['http://127.0.0.1:8080', 'http://localhost:8080/', 'https://cardify.test']:
            with self.subTest(value=value): self.assertTrue(guard.allowed_target(value))

if __name__ == '__main__': unittest.main()
