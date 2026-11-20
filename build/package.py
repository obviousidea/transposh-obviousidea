"""Build a complete local candidate ZIP; never upload, tag, or publish a release.

Requires Python 3 and `npm ci --ignore-scripts`. No vendor licences/settings or
installed .BAK files are copied. The existing source version is not incremented.
"""
from pathlib import Path
import argparse
from datetime import date, datetime, timezone
import hashlib
import json
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'transposh-translation-filter-for-wordpress'


def build(build_date, release=False):
    version = re.search(r'^\s*Version:\s*(\S+)', (ROOT / 'transposh.php').read_text(), re.M).group(1)
    files = {}
    for folder in ['core', 'assets', 'js', 'css', 'img', 'langs', 'widgets', 'wp']:
        for path in sorted((ROOT / folder).rglob('*')):
            # '.BAK.' files, dotfiles and macOS 'name 2.ext' duplicates are not shipped
            if path.is_file() and '.BAK.' not in path.name and not path.name.startswith('.') \
                    and not re.search(r' \d+\.[A-Za-z0-9]+$', path.name):
                files[path.relative_to(ROOT).as_posix()] = path.read_bytes()
    for name in ['transposh.php', 'readme.txt', 'index.html']:
        files[name] = (ROOT / name).read_bytes()
    scripts = {name: value.decode('utf-8') for name, value in files.items() if name.endswith('.js')}
    result = subprocess.run(['node', str(ROOT / 'build/minify.cjs')],
                            input=json.dumps(scripts), capture_output=True, text=True, check=True, encoding='utf-8')
    files.update({name: value.encode('utf-8') for name, value in json.loads(result.stdout).items()})
    for name, value in list(files.items()):
        if name.endswith(('.php', '.txt')):
            text = value.decode('utf-8').replace('\r\n', '\n')
            text = text.replace('%VERSION%', version).replace('%YEAR%', str(build_date.year)).replace('%DATE%', build_date.isoformat() + ('' if release else ' candidate'))
            files[name] = text.encode('utf-8')
    output = ROOT / 'build/artifacts'
    output.mkdir(exist_ok=True)
    archive = output / ('transposh-obviousidea-' + version + '.zip' if release else 'transposh-www2-candidate.zip')
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        for name, value in sorted(files.items()):
            info = zipfile.ZipInfo(SLUG + '/' + name, date_time=(build_date.year, build_date.month, build_date.day, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            bundle.writestr(info, value)
    manifest = {'version': version, 'candidate_only': not release, 'build_date': build_date.isoformat(),
                'zip_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
                'files': {name: hashlib.sha256(value).hexdigest() for name, value in sorted(files.items())}}
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    print(json.dumps({'archive': str(archive), 'files': len(files), 'sha256': manifest['zip_sha256']}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--build-date', type=date.fromisoformat,
                        default=datetime.now(timezone.utc).date(),
                        help='YYYY-MM-DD; freeze this value to reproduce a prior package')
    parser.add_argument('--release', action='store_true', help='Build the versioned release ZIP locally; never publish it')
    args = parser.parse_args()
    if not 1980 <= args.build_date.year <= 2107:
        parser.error('Build date must fit the ZIP timestamp range, 1980–2107.')
    build(args.build_date, args.release)
