"""Real Git-history controls; no network, user configuration or store data."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from quality_source_policy import private_path

GUARD = Path(__file__).with_name('guard-private-data.py').resolve()


class PrivateHistoryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='akb-git-policy-')
        self.root = Path(self.temp.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'Local fixture')
        self.git('config', 'user.email', 'fixture@example.test')
        self.write('README.md', 'Source only')
        self.commit()
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.git('update-ref', 'refs/remotes/origin/main', self.base)

    def tearDown(self): self.temp.cleanup()

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, capture_output=True, text=True, check=True).stdout

    def write(self, name, value):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value)

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'Fixture change')

    def guard(self, *args, input=''):
        return subprocess.run(['python3', str(GUARD), *args], input=input, cwd=self.root, text=True, capture_output=True)

    def test_clean_source_passes(self):
        self.write('src/Contract.php', '<?php return true;')
        self.commit()
        self.assertEqual(0, self.guard('--revision', 'HEAD').returncode)

    def test_private_paths_are_blocked(self):
        for path in ('.local-backups/backup.tar.gz', 'database/production.sql', 'public_html/wp-content/uploads/review.png',
                     'public_html/wp-config.php', '.env.local', 'output/receipt.json', 'private-key.pem',
                     'assets/production.sql.xz', 'assets/production.sqlite', 'assets/production.sqlite3.zst',
                     'assets/production.sql.gz.xz', 'assets/production.dump.bz2', 'assets/production.db.zip'):
            with self.subTest(path=path): self.assertTrue(private_path(path))
        self.write('database/production.sql', 'SYNTHETIC FIXTURE')
        self.commit()
        self.assertEqual(1, self.guard('--revision', 'HEAD').returncode)

    def test_deleted_private_history_still_blocks_push(self):
        self.write('.local-backups/synthetic.txt', 'fixture')
        self.commit()
        self.git('rm', '-q', '.local-backups/synthetic.txt')
        self.git('commit', '-qm', 'Remove fixture')
        self.assertEqual(0, self.guard('--revision', 'HEAD').returncode)
        head = self.git('rev-parse', 'HEAD').strip()
        result = self.guard('--pre-push', 'origin', input=f'refs/heads/main {head} refs/heads/main {self.base}\n')
        self.assertEqual(1, result.returncode)

    def test_secret_is_blocked_without_printing_it(self):
        secret = '-----BEGIN ' + 'PRIVATE KEY-----'
        self.write('src/config.txt', secret)
        self.commit()
        result = self.guard('--revision', 'HEAD')
        self.assertEqual(1, result.returncode)
        self.assertNotIn(secret, result.stdout + result.stderr)

    def test_unmeasured_revision_and_malformed_hook_input_fail(self):
        self.assertEqual(2, self.guard('--revision', 'does-not-exist').returncode)
        self.assertEqual(2, self.guard('--pre-push', 'origin', input='incomplete\n').returncode)

    def test_reference_deletion_exports_no_data(self):
        result = self.guard('--pre-push', 'origin', input=f'(delete) {"0" * 40} refs/heads/old {self.base}\n')
        self.assertEqual(0, result.returncode)

    def test_new_branch_never_trusts_stale_remote_tracking_refs(self):
        self.write('database/production.sql', 'SYNTHETIC FIXTURE')
        self.commit()
        self.git('update-ref', 'refs/remotes/origin/deleted-branch', self.git('rev-parse', 'HEAD').strip())
        self.git('rm', '-q', 'database/production.sql')
        self.git('commit', '-qm', 'Remove fixture')
        head = self.git('rev-parse', 'HEAD').strip()
        result = self.guard('--pre-push', 'origin', input=f'refs/heads/new {head} refs/heads/new {"0" * 40}\n')
        self.assertEqual(1, result.returncode)

    def test_secret_in_tag_and_commit_metadata_is_not_exposed(self):
        secret = 'ghp_' + 'x' * 35
        self.git('commit', '--allow-empty', '-qm', secret)
        self.git('tag', '-a', 'fixture-tag', '-m', secret)
        tag = self.git('rev-parse', 'fixture-tag').strip()
        result = self.guard('--pre-push', 'origin', input=f'refs/tags/fixture-tag {tag} refs/tags/fixture-tag {"0" * 40}\n')
        self.assertEqual(1, result.returncode)
        self.assertNotIn(secret, result.stdout + result.stderr)

    def test_nested_tag_metadata_is_also_checked(self):
        secret = 'ghp_' + 'y' * 35
        self.git('tag', '-a', 'inner', '-m', secret)
        self.git('tag', '-a', 'outer', 'inner', '-m', 'Clean outer message')
        tag = self.git('rev-parse', 'outer').strip()
        result = self.guard('--pre-push', 'origin', input=f'refs/tags/outer {tag} refs/tags/outer {"0" * 40}\n')
        self.assertEqual(1, result.returncode)
        self.assertNotIn(secret, result.stdout + result.stderr)


if __name__ == '__main__': unittest.main()
