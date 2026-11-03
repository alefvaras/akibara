import importlib.util
from pathlib import Path
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('agent_policy', Path(__file__).with_name('check-antigravity-policy.py'))
POLICY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(POLICY)


class PolicyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='akb-policy-')
        self.root = Path(self.temp.name)
        (self.root / '.agents/rules').mkdir(parents=True)
        (self.root / 'AGENTS.md').write_text('\n'.join(POLICY.RULES))
        for name in POLICY.RULES: (self.root / name).write_text('---\ntrigger: always_on\n---\nShared rule\n')

    def tearDown(self): self.temp.cleanup()

    def test_valid_native_rules_pass(self): self.assertEqual([], POLICY.check(self.root))

    def test_missing_rule_blocks(self):
        (self.root / POLICY.RULES[0]).unlink()
        self.assertTrue(POLICY.check(self.root))

    def test_manual_activation_cannot_replace_always_on(self):
        (self.root / POLICY.RULES[0]).write_text('---\ntrigger: manual\n---\nRule\n')
        self.assertTrue(POLICY.check(self.root))

    def test_truncated_policy_blocks(self):
        path = self.root / POLICY.RULES[0]
        path.write_text(path.read_text() + 'x' * POLICY.MAX_BYTES)
        self.assertTrue(POLICY.check(self.root))


if __name__ == '__main__': unittest.main()
