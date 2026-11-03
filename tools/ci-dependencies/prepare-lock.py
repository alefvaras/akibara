#!/usr/bin/env python3
"""Prepare a NEW, reviewable vendor lock candidate from exact official versions."""
import argparse
import json
from pathlib import Path
import re
import shutil
import sys
import tempfile
import urllib.parse
import urllib.request

sys.dont_write_bytecode = True
import materialize as inputs


def resolve_origin(name, version):
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('An exact N.N.N version is required')
    if name == 'wordpress':
        with urllib.request.urlopen('https://wordpress.org/download/releases/', timeout=45) as response:
            html = response.read().decode()
        expected = 'https://wordpress.org/wordpress-' + version + '.zip'
        if expected not in re.findall(r'href=[\"\x27]([^\"\x27]+)', html):
            raise ValueError('Exact WordPress archive not listed by its official release page')
        return expected, 'official-archive'
    query = urllib.parse.urlencode({'action': 'plugin_information', 'request[slug]': name, 'request[fields][versions]': '1'})
    with urllib.request.urlopen('https://api.wordpress.org/plugins/info/1.2/?' + query, timeout=45) as response:
        metadata = json.load(response)
    versions = metadata.get('versions', {})
    origin = versions.get(version) if isinstance(versions, dict) else None
    if origin:
        return inputs.official_url(origin), 'official-archive'
    if name == 'bluex-for-woocommerce' and metadata.get('version') == version:
        return inputs.official_url(metadata['download_link']), 'reviewed-source-snapshot'
    raise ValueError('Exact plugin version unavailable from official metadata: ' + name)


def prepare(versions, destination):
    destination = destination.absolute()
    if destination.exists():
        raise ValueError('Candidate destination must be new; never update the live lock in place')
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='akb-pin-review-', dir=destination.parent) as temporary:
        work = Path(temporary)
        candidate = work / 'candidate'
        candidate.mkdir()
        entries = []
        for name, version in versions.items():
            origin, kind = resolve_origin(name, version)
            archive = work / (name + '.zip')
            opener = urllib.request.build_opener(inputs.OfficialRedirect())
            with opener.open(origin, timeout=45) as response, archive.open('wb') as stream:
                inputs.official_url(response.url)
                count = 0
                while chunk := response.read(1024 * 1024):
                    count += len(chunk)
                    if count > inputs.MAX_UNPACKED:
                        raise ValueError('Archive exceeds acquisition size limit')
                    stream.write(chunk)
            entry = {'name': name, 'version': version, 'kind': kind, 'origin': origin,
                     'archive_sha256': inputs.digest(archive.read_bytes()), 'archive_bytes': count,
                     'archive_prefix': 'wordpress/wp-includes' if name == 'wordpress' else name,
                     'destination': inputs.DESTINATIONS[name]}
            source = work / ('source-' + name)
            source.mkdir()
            inputs.unpack(entry, archive, source)
            header = {'wordpress': 'version.php', 'bluex-for-woocommerce': 'woocommerce-bluex.php'}.get(name, name + '.php')
            text = (source / header).read_text()
            pattern = r"\$wp_version\s*=\s*'([^']+)'" if name == 'wordpress' else r'^\s*\*\s*Version:\s*(\S+)'
            match = re.search(pattern, text, re.MULTILINE)
            if not match or match[1] != version:
                raise ValueError('Official download header differs from the requested version: ' + name)
            entry['files'] = inputs.tree_manifest(source)
            if kind == 'reviewed-source-snapshot':
                entry['snapshot'] = 'vendor-contracts/' + name
                entry['reason'] = 'Official metadata does not expose the requested version archive. Review this immutable source snapshot; CI never fetches its mutable origin.'
                shutil.copytree(source, candidate / entry['snapshot'])
            entries.append(entry)
            if name == 'wordpress':
                admin = {key: value for key, value in entry.items() if key != 'files'}
                admin.update(name='wordpress-admin', archive_prefix='wordpress/wp-admin',
                             destination=inputs.DESTINATIONS['wordpress-admin'])
                admin_source = work / 'source-wordpress-admin'
                admin_source.mkdir()
                inputs.unpack(admin, archive, admin_source)
                admin['files'] = inputs.tree_manifest(admin_source)
                entries.append(admin)
        lock = candidate / 'dependencies.lock.json'
        lock.write_text(json.dumps({'schema': 1, 'purpose': 'vendor-contract-test-inputs', 'dependencies': entries}, indent=2) + '\n')
        inputs.load_lock(lock)
        candidate.rename(destination)
    return destination


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wordpress', required=True)
    parser.add_argument('--woocommerce', required=True)
    parser.add_argument('--mercadopago', required=True)
    parser.add_argument('--bluex', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        result = prepare({'wordpress': args.wordpress, 'woocommerce': args.woocommerce,
                          'woocommerce-mercadopago': args.mercadopago, 'bluex-for-woocommerce': args.bluex}, args.output)
        print('Candidate only; review provenance, contracts and full CI before adoption: ' + str(result))
    except (ValueError, OSError, KeyError, TypeError) as error:
        parser.exit(1, 'Dependency candidate rejected: ' + str(error) + '\n')
