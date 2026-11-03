"""A correctly hashed archive must still reject persisted state and test data."""
import hashlib
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / 'tools/akibara-releases/verify-runtime-package.py'
PACKAGER = ROOT / 'tools/akibara-releases/package-runtime.sh'
SLUG = 'akibara-search'
VERSION = '9.9.9'
FORBIDDEN = (
    'assets/production.sql.gz', 'assets/production.sql.xz',
    'assets/catalog.sqlite', 'assets/database/products.json',
    'assets/uploads/reviews/photo.jpg', 'assets/.env.production',
    'assets/.env.example', 'assets/wp-config.php', 'assets/config.json',
    'assets/keys/public.pem', 'assets/api.key', 'assets/caches/catalog.json',
    'assets/logs/request.log.1', 'assets/seeds/reviews.json',
    'src/Fixtures/reviews.php', 'assets/reviews-fixture.json',
    'assets/tests/reviews.json', 'assets/.local-backups/products.json',
)


def runtime_source():
    files = {
        'akibara-search.php': "<?php\n/**\n * Version: 9.9.9\n */\ndefine( 'AKIBARA_SEARCH_VERSION', '9.9.9' );\n",
        'uninstall.php': '<?php // Runtime.\n',
        'src/Search.php': '<?php // Runtime.\n',
        'src/Install.php': '<?php // Runtime.\n',
        'src/Reviews/Cache.php': '<?php // A runtime class is not persisted cache.\n',
        'frontend/facets-render.php': '<?php // Runtime.\n',
        'templates/admin/tab.php': '<?php // Runtime.\n',
        'assets/css/style.css': 'body{}',
        'assets/js/script.js': 'void 0;',
        'assets/data/labels.json': '{"unavailable":"Agotado"}',
    }
    return {name: value.encode() for name, value in files.items()}


class RuntimeArtifactBoundary(unittest.TestCase):
    def test_correctly_sealed_runtime_assets_pass(self):
        self.assertEqual(0, self.verify(runtime_source()).returncode)
        self.assertEqual(0, self.package(runtime_source()).returncode)

    def test_private_data_cannot_be_laundered_by_valid_hashes(self):
        for name in FORBIDDEN:
            with self.subTest(path=name):
                files = runtime_source() | {name: b'SYNTHETIC PRIVATE DATA'}
                result = self.verify(files)
                self.assertEqual(1, result.returncode, result.stdout + result.stderr)
                self.assertIn('Artefacto privado o ajeno al runtime', result.stderr)

    def test_packager_uses_the_same_private_data_boundary(self):
        for name in FORBIDDEN:
            with self.subTest(path=name):
                result = self.package(runtime_source() | {name: b'SYNTHETIC PRIVATE DATA'})
                self.assertEqual(1, result.returncode, result.stdout + result.stderr)
                self.assertIn('Artefacto privado o ajeno al runtime', result.stderr)

    def verify(self, files):
        with tempfile.TemporaryDirectory(prefix='akb-runtime-seal-') as temporary:
            directory = Path(temporary)
            archive = directory / f'{SLUG}-{VERSION}.zip'
            inventory = directory / 'files.sha256'
            checksum = directory / 'archive.sha256'
            with zipfile.ZipFile(archive, 'w') as output:
                for name, value in files.items(): output.writestr(f'{SLUG}/{name}', value)
            inventory.write_text(''.join(f'{hashlib.sha256(value).hexdigest()}  {SLUG}/{name}\n' for name, value in files.items()))
            checksum.write_text(f'{hashlib.sha256(archive.read_bytes()).hexdigest()}  {archive.name}\n')
            return subprocess.run(['python3', str(VERIFIER), str(archive), str(inventory), str(checksum), SLUG, VERSION],
                                  cwd=directory, text=True, capture_output=True)

    def package(self, files):
        with tempfile.TemporaryDirectory(prefix='akb-runtime-boundary-') as temporary:
            directory = Path(temporary)
            source = directory / 'source'
            for name, value in files.items():
                filename = source / name
                filename.parent.mkdir(parents=True, exist_ok=True)
                filename.write_bytes(value)
            result = subprocess.run(['bash', str(PACKAGER), 'search', '--source-dir', str(source), '--output-dir', str(directory / 'dist')],
                                    cwd=directory, text=True, capture_output=True)
            if result.returncode:
                self.assertFalse((directory / 'dist').exists(), 'rejected package must not reach output')
            return result


if __name__ == '__main__': unittest.main()
