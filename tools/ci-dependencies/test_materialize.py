"""Offline adversarial controls for vendor test-input materialization."""
import importlib.util
import io
import json
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch
import warnings
import zipfile

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('materialize', Path(__file__).with_name('materialize.py'))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class MaterializeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='akb-vendor-test-')
        self.base = Path(self.temporary.name)
        self.root = self.base / 'checkout'
        self.cache = self.base / 'cache'
        self.cache.mkdir()
        self.lock = self.base / 'dependencies.lock.json'
        self.entries = []
        for name, destination in m.DESTINATIONS.items():
            self.entries.append(self.archive_entry(name, destination))
        self.save()

    def tearDown(self):
        self.temporary.cleanup()

    def archive_entry(self, name, destination, extra=None):
        payload = b'<?php // SYNTHETIC SOURCE ONLY\n'
        archive = self.base / (name + '.zip')
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            with zipfile.ZipFile(archive, 'w') as zipped:
                zipped.writestr(name + '/source.php', payload)
                if extra:
                    zipped.writestr(*extra)
        raw = archive.read_bytes()
        sha = m.digest(raw)
        (self.cache / (sha + '.zip')).write_bytes(raw)
        return {'name': name, 'destination': destination, 'version': '1.2.3',
                'kind': 'official-archive', 'origin': 'https://downloads.wordpress.org/plugin/' + name + '.1.2.3.zip',
                'archive_prefix': name, 'archive_sha256': sha, 'archive_bytes': len(raw),
                'files': {'source.php': m.digest(payload)}}

    def save(self):
        self.lock.write_text(json.dumps({'schema': 1, 'purpose': 'vendor-contract-test-inputs', 'dependencies': self.entries}))

    def run_materialize(self, **kwargs):
        return m.materialize(self.root, self.lock, self.cache, offline=True, **kwargs)

    def assert_no_destinations(self):
        for destination in m.DESTINATIONS.values():
            self.assertFalse((self.root / destination).exists())

    def test_exact_cached_archives_materialize_and_verify_idempotently(self):
        self.assertEqual(len(m.DESTINATIONS), len(self.run_materialize()))
        self.assertEqual(len(m.DESTINATIONS), len(self.run_materialize(verify_only=True)))
        self.assertEqual(len(m.DESTINATIONS), len(self.run_materialize()))

    def test_corrupt_cached_archive_fails_before_any_destination_is_written(self):
        entry = self.entries[-1]
        (self.cache / (entry['archive_sha256'] + '.zip')).write_bytes(b'corrupt')
        with self.assertRaisesRegex(ValueError, 'digest/size mismatch'):
            self.run_materialize()
        self.assert_no_destinations()

    def test_offline_missing_archive_is_not_a_success_or_network_fallback(self):
        (self.cache / (self.entries[-1]['archive_sha256'] + '.zip')).unlink()
        with self.assertRaisesRegex(ValueError, 'offline cache'):
            self.run_materialize()
        self.assert_no_destinations()

    def test_https_download_is_verified_before_cache_publication(self):
        entry = self.entries[0]
        cached = self.cache / (entry['archive_sha256'] + '.zip')
        payload = cached.read_bytes()
        cached.unlink()
        response = io.BytesIO(payload)
        response.url = entry['origin']
        with patch.object(m.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = response
            self.assertEqual(cached, m.archive_for(entry, self.cache))
        self.assertEqual(payload, cached.read_bytes())
        self.assertEqual([], list(self.cache.glob('*.partial')))

    def test_bad_https_response_leaves_no_cache_or_partial_artifact(self):
        entry = self.entries[0]
        cached = self.cache / (entry['archive_sha256'] + '.zip')
        payload = cached.read_bytes()
        cached.unlink()
        for body, url in [(payload + b'extra', entry['origin']), (b'wrong', entry['origin']),
                          (payload, 'https://untrusted.example.test/archive.zip')]:
            with self.subTest(url=url, size=len(body)):
                response = io.BytesIO(body)
                response.url = url
                with patch.object(m.urllib.request, 'build_opener') as opener:
                    opener.return_value.open.return_value = response
                    with self.assertRaises(ValueError):
                        m.archive_for(entry, self.cache)
                self.assertFalse(cached.exists())
                self.assertEqual([], list(self.cache.glob('*.partial')))

    def test_existing_modified_vendor_input_is_never_overwritten(self):
        self.run_materialize()
        file = self.root / self.entries[0]['destination'] / 'source.php'
        file.write_text('authoritative edit')
        with self.assertRaisesRegex(ValueError, 'refusing overwrite'):
            self.run_materialize()
        self.assertEqual('authoritative edit', file.read_text())

    def test_extra_existing_file_is_detected(self):
        self.run_materialize()
        (self.root / self.entries[0]['destination'] / 'unreviewed.php').write_text('extra')
        with self.assertRaisesRegex(ValueError, 'refusing overwrite'):
            self.run_materialize(verify_only=True)

    def test_configured_wordpress_is_refused(self):
        (self.root / 'public_html').mkdir(parents=True)
        (self.root / 'public_html/wp-config.php').write_text('<?php // synthetic')
        with self.assertRaisesRegex(ValueError, 'configured WordPress'):
            self.run_materialize()
        self.assert_no_destinations()

    def test_destination_ancestor_symlink_cannot_escape_root(self):
        self.root.mkdir()
        outside = self.base / 'outside'
        outside.mkdir()
        (self.root / 'public_html').symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'symlink'):
            self.run_materialize()
        self.assertEqual([], list(outside.iterdir()))

    def test_traversal_even_outside_selected_archive_prefix_is_rejected(self):
        for filename in ('../escape.php', 'elsewhere/../escape.php', '/absolute.php', 'x\\escape.php'):
            with self.subTest(filename=filename):
                self.entries[0] = self.archive_entry('wordpress', m.DESTINATIONS['wordpress'], (filename, 'bad'))
                self.save()
                with self.assertRaisesRegex(ValueError, 'Unsafe'):
                    self.run_materialize()
                self.assert_no_destinations()

    def test_duplicate_or_symlink_archive_members_are_rejected(self):
        link = zipfile.ZipInfo('wordpress/link.php')
        link.create_system = 3
        link.external_attr = (stat.S_IFLNK | 0o777) << 16
        for extra in [('wordpress/source.php', 'duplicate'), (link, '/etc/passwd')]:
            with self.subTest(extra=str(extra[0])):
                self.entries[0] = self.archive_entry('wordpress', m.DESTINATIONS['wordpress'], extra)
                self.save()
                with self.assertRaisesRegex(ValueError, 'Duplicate|Special'):
                    self.run_materialize()
                self.assert_no_destinations()

    def test_resealed_archive_with_unlisted_file_is_rejected(self):
        self.entries[0] = self.archive_entry('wordpress', m.DESTINATIONS['wordpress'], ('wordpress/extra.php', 'extra'))
        self.save()
        with self.assertRaisesRegex(ValueError, 'locked file manifest'):
            self.run_materialize()
        self.assert_no_destinations()

    def test_untrusted_origin_or_destination_cannot_be_materialized(self):
        original = self.entries[0].copy()
        for change in ({'origin': 'http://wordpress.org/x.zip'}, {'origin': 'https://wordpress.org.attacker.test/x.zip'},
                       {'origin': 'file:///tmp/x.zip'}, {'origin': 'https://user:password@wordpress.org/x.zip'},
                       {'destination': '../outside'}):
            with self.subTest(change=change):
                self.entries[0] = {**original, **change}
                self.save()
                with self.assertRaises(ValueError):
                    self.run_materialize()
                self.assert_no_destinations()

    def test_reviewed_snapshot_is_verified_and_mutation_is_rejected(self):
        entry = self.entries[-1]
        source = self.base / 'vendor-contracts/bluex-for-woocommerce'
        source.mkdir(parents=True)
        (source / 'source.php').write_bytes(b'<?php // SYNTHETIC SOURCE ONLY\n')
        entry.update(kind='reviewed-source-snapshot', snapshot='vendor-contracts/bluex-for-woocommerce')
        self.save()
        self.assertEqual(len(m.DESTINATIONS), len(self.run_materialize()))
        import shutil
        shutil.rmtree(self.root)
        (source / 'source.php').write_text('modified')
        with self.assertRaisesRegex(ValueError, 'snapshot digest/tree mismatch'):
            self.run_materialize()
        self.assert_no_destinations()


if __name__ == '__main__':
    unittest.main()
