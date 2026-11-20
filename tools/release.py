#!/usr/bin/env python3
"""Regenerate gettext assets and build a runtime-only review ZIP using stdlib."""
import ast
import gettext
import json
from pathlib import Path
import re
import struct
import zipfile

root = Path(__file__).resolve().parents[1]
plugin = root
main = (plugin / 'category-price-manager.php').read_text()
version = re.search(r'^ \* Version: (.+)$', main, re.M)[1]
assert re.search(r"const VERSION = '([^']+)'", main)[1] == version
readme = (plugin / 'readme.txt').read_text()
assert f'Stable tag: {version}\n' in readme
assert 'Contributors: hesamst\n' in readme
assert re.search(r'^Requires at least: (.+)$', readme, re.M)[1] == re.search(r'^ \* Requires at least: (.+)$', main, re.M)[1]
assert re.search(r'^Requires PHP: (.+)$', readme, re.M)[1] == re.search(r'^ \* Requires PHP: (.+)$', main, re.M)[1]
assert len(readme.split('\n\n')[1]) <= 150
lang = plugin / 'languages'
po = lang / 'category-price-manager-fa_IR.po'
entries = {}
key = value = None
active = None
for line in po.read_text().splitlines() + ['']:
    if line.startswith('msgid '):
        key = ast.literal_eval(line[6:]); value = ''; active = 'key'
    elif line.startswith('msgstr '):
        value = ast.literal_eval(line[7:]); active = 'value'
    elif line.startswith('"'):
        if active == 'key': key += ast.literal_eval(line)
        elif active == 'value': value += ast.literal_eval(line)
    elif not line.strip() and key is not None:
        entries[key] = value
        key = value = active = None
messages = set(re.findall(r"(?:__|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'([^']*)',\s*'category-price-manager'", main))
header = f'Project-Id-Version: Category Price Manager {version}\nLanguage: fa_IR\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n > 1);\n'
entries = {'': header, **{m: entries.get(m, '') for m in sorted(messages)}}
quote = lambda s: json.dumps(s, ensure_ascii=False)
po.write_text('# Persian translation for Category Price Manager.\n# Copyright (C) 2026 Hesam MM (hesamst)\n# Distributed under GPL-2.0-or-later.\n' + '\n'.join(f'\nmsgid {quote(k)}\nmsgstr {quote(v)}' for k,v in entries.items()) + '\n')
# Untranslated entries stay out of MO so gettext falls back to source English.
compiled = [(k, v) for k,v in sorted(entries.items()) if not k or v]
keys = [k.encode() + b'\0' for k,v in compiled]
values = [v.encode() + b'\0' for k,v in compiled]
start = 28 + len(keys) * 16
offset = start
tables = []
for group in (keys,values):
    for value in group:
        tables.append(struct.pack('<II', len(value)-1, offset))
        offset += len(value)
mo = lang / 'category-price-manager-fa_IR.mo'
mo.write_bytes(struct.pack('<7I', 0x950412de, 0, len(keys), 28, 28+len(keys)*8, 0, 0) + b''.join(tables+keys+values))
with mo.open('rb') as stream:
    catalogue = gettext.GNUTranslations(stream)
for k,v in compiled:
    if k:
        assert catalogue.gettext(k) == v
        assert sorted(re.findall(r'%\d*\$?[sdf]', k)) == sorted(re.findall(r'%\d*\$?[sdf]', v)), k
pot_header = f'Project-Id-Version: Category Price Manager {version}\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n'
(lang / 'category-price-manager.pot').write_text('# Category Price Manager translation template.\n# Copyright (C) 2026 Hesam MM (hesamst)\n# Distributed under GPL-2.0-or-later.\nmsgid ""\nmsgstr '+quote(pot_header)+'\n' + ''.join(f'\nmsgid {quote(m)}\nmsgstr ""\n' for m in sorted(messages)))
files = [plugin / p for p in ['category-price-manager.php','readme.txt','LICENSE','license.txt']]
files += sorted(p for p in (plugin / 'assets').rglob('*') if p.is_file() and p.suffix in {'.css', '.js'})
files += sorted(p for p in lang.glob('*') if p.is_file() and p.suffix in {'.po', '.mo', '.pot'})
output = root / 'dist' / f'category-price-manager-{version}-review-candidate.zip'
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
    for p in sorted(files):
        assert p.is_file() and not p.is_symlink()
        archive.write(p, 'category-price-manager/' + str(p.relative_to(plugin)))
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
    assert all(n.startswith('category-price-manager/') and '..' not in n.split('/') for n in archive.namelist())
    assert len(archive.namelist()) == len(files)
print(f'Metadata and archive checks passed: {len(files)} runtime files.')
print(f'Extracted {len(messages)} gettext messages; {sum(bool(k and v) for k,v in entries.items())} Persian translations; untranslated messages fall back to English.')
print(output)
