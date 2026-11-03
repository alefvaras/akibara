"""Paths that must never enter Akibara's shared source history or releases."""
import re
from pathlib import PurePosixPath


def private_path(value):
    path = PurePosixPath(value)
    parts = tuple(part.lower() for part in path.parts)
    name = parts[-1] if parts else ''
    return (
        any(part.startswith(('.local-', '.local_', '.codex')) for part in parts)
        or any(part in {'database', 'uploads', 'backups', 'node_modules', 'output', 'outputs', 'cache', 'logs'} for part in parts)
        or name == 'wp-config.php' or name.startswith('.env') and name not in {'.env.example', '.env.sample'}
        or name in {'id_rsa', 'id_ed25519', 'id_dsa'}
        or bool(re.search(r'\.(?:sql|sqlite3?|db|dump)(?:\.(?:gz|gzip|zip|bz2|xz|zst|7z|lz4|lzma|br))*$', name))
        or bool(re.search(r'(?:\.log(?:\.\d+)?|\.bak)$', name))
        or bool(re.search(r'(?:private|secret).*\.(?:pem|key)$', name))
    )


# These signatures are high confidence. Matching lines and values are never printed.
SECRET_PATTERN = (r'-----BEGIN (RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----'
                  r'|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}'
                  r'|AKIA[0-9A-Z]{16}|sk-(proj-|svcacct-)?[A-Za-z0-9_-]{32,}')


def runtime_artifact_path(value):
    """Non-runtime/private artifacts are forbidden even inside a sealed release.

    Runtime review/cache classes and JSON assets remain valid code. Test data,
    uploaded reviews, credentials, local configuration and persisted state do not.
    This is a path boundary, not a claim to identify arbitrary disguised content.
    """
    parts = tuple(part.lower() for part in PurePosixPath(value).parts)
    name = parts[-1] if parts else ''
    forbidden_dirs = {
        'test', 'tests', 'fixture', 'fixtures', '__fixtures__', 'seed', 'seeds',
        'test-data', 'testdata', 'review-fixtures', 'reviews-fixtures', 'caches',
        'wc-logs', 'keys', 'credentials', 'secrets', 'config', 'configs',
        'migrations', 'graphify-out', 'coverage', '.git', '.github', '.idea',
        '.vscode', '__pycache__',
    }
    forbidden_names = {
        '.ds_store', '.phpunit.result.cache', 'coverage.xml', 'error_log',
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
        'yarn.lock', 'pnpm-lock.yaml', 'phpunit.xml', 'phpunit.xml.dist',
        'php.ini', '.htaccess', '.user.ini', 'config.php', 'config.json',
        'config.yaml', 'config.yml',
    }
    return (
        private_path(value)
        or any(part in forbidden_dirs for part in parts[:-1])
        or name.startswith(('.env', '._')) or name in forbidden_names
        or bool(re.search(r'\.(?:sql|sqlite3?|db|dump)(?:\.(?:gz|bz2|xz|zip|zst))*$', name))
        or bool(re.search(r'\.(?:pem|key|p12|pfx|bak|bk|orig|rej|tmp|temp|backup|tar|tgz|zip|gz|bz2|xz|zst)$', name))
        or name.endswith('~')
        or bool(re.search(r'(?:^|[-_.])(?:fixtures?|seeds?|test-data|reviews?-fixtures?)(?:[-_.]|$)', name))
    )
