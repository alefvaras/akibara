"""Source-export boundary controls using synthetic local files only."""
import contextlib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('quality_export', Path(__file__).with_name('export-quality-source.py'))
EXPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(EXPORT)


class SourceExportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='akb-export-')
        self.base = Path(self.temp.name).resolve()
        self.root = self.base / 'source'
        self.allowed = self.root / 'allowed'
        self.allowed.mkdir(parents=True)
        self.original = EXPORT.ROOT, EXPORT.DIRECTORIES, EXPORT.FILES
        EXPORT.ROOT, EXPORT.DIRECTORIES, EXPORT.FILES = self.root, ['allowed'], []

    def tearDown(self):
        EXPORT.ROOT, EXPORT.DIRECTORIES, EXPORT.FILES = self.original
        self.temp.cleanup()

    def export(self):
        with contextlib.redirect_stdout(io.StringIO()): EXPORT.export(self.base / 'exported')

    def test_allowed_source_is_copied_without_database_formats_or_bytecode(self):
        (self.allowed / 'contract.php').write_text('<?php return true;')
        private = ['production.sql.gz', 'production.sql.xz', 'production.sqlite', 'production.sqlite3.zst']
        for name in private: (self.allowed / name).write_text('SYNTHETIC FIXTURE')
        (self.allowed / '__pycache__').mkdir()
        (self.allowed / '__pycache__/module.pyc').write_text('fixture')
        self.export()
        target = self.base / 'exported/allowed'
        self.assertTrue((target / 'contract.php').is_file())
        self.assertEqual(['contract.php'], [path.name for path in target.iterdir()])

    def test_symlinked_source_root_cannot_import_external_data(self):
        self.allowed.rmdir()
        private = self.base / '.local-backups'
        private.mkdir()
        (private / 'customer.csv').write_text('SYNTHETIC FIXTURE')
        self.allowed.symlink_to(private, target_is_directory=True)
        with self.assertRaises(RuntimeError): self.export()
        self.assertFalse((self.base / 'exported').exists())

    def test_symlinked_file_is_rejected_before_any_copy(self):
        private = self.base / 'customer.csv'
        private.write_text('SYNTHETIC FIXTURE')
        (self.allowed / 'public.csv').symlink_to(private)
        with self.assertRaises(RuntimeError): self.export()
        self.assertFalse((self.base / 'exported').exists())

    def test_existing_destination_cannot_keep_old_residue(self):
        target = self.base / 'exported'
        target.mkdir()
        (target / 'old.txt').write_text('fixture')
        with self.assertRaises(RuntimeError): self.export()
        self.assertEqual('fixture', (target / 'old.txt').read_text())


if __name__ == '__main__': unittest.main()
