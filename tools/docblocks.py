#!/usr/bin/env python3
"""Ergaenzt fehlende Docblocks aus der tatsaechlichen Signatur.

Liest den phpcs-JSON-Bericht und behandelt ausschliesslich Kommentar- und
Dokumentationsverstoesse. Logik wird nicht angefasst: eingefuegt werden nur
Kommentarzeilen und - bei @inheritDoc - das Attribut #[\\Override].

Aufruf:
    phpcs --standard=moodle --severity=1 --extensions=php \
          --exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment \
          --ignore=catmodel/ --report=json . > /tmp/cs.json
    python3 tools/docblocks.py /tmp/cs.json
"""

import json
import re
import sys

HANDLED = {
    'moodle.Commenting.MissingDocblock.Function',
    'moodle.Commenting.MissingDocblock.Class',
    'moodle.Commenting.MissingDocblock.Interface',
    'moodle.Commenting.MissingDocblock.Constant',
    'Squiz.Commenting.VariableComment.Missing',
    'moodle.Commenting.DocblockDescription.Missing',
    'moodle.Commenting.ValidTags.Invalid',
    'Squiz.Commenting.InlineComment.NotCapital',
}

# Woerter, die am Satzanfang gross geschrieben die uebliche Moodle-Formulierung ergeben.
VERB = {
    'get': 'Returns', 'is': 'Returns whether', 'has': 'Returns whether',
    'set': 'Sets', 'add': 'Adds', 'create': 'Creates', 'make': 'Creates',
    'update': 'Updates', 'delete': 'Deletes', 'remove': 'Removes',
    'render': 'Renders', 'export': 'Exports', 'build': 'Builds',
    'find': 'Finds', 'count': 'Counts', 'load': 'Loads', 'save': 'Saves',
    'init': 'Initialises', 'reset': 'Resets', 'validate': 'Validates',
    'test': 'Tests', 'check': 'Checks', 'fetch': 'Fetches', 'apply': 'Applies',
    'format': 'Formats', 'process': 'Processes', 'prepare': 'Prepares',
    'assign': 'Assigns', 'unassign': 'Unassigns', 'define': 'Defines',
}


def humanise(name):
    """Macht aus einem Bezeichner eine Beschreibung in Moodle-Diktion."""
    words = re.sub(r'(?<!^)(?=[A-Z])', '_', name).lower().split('_')
    words = [w for w in words if w]
    if not words:
        return 'Undocumented.'
    head = words[0]
    if head in VERB:
        rest = ' '.join(words[1:])
        return f'{VERB[head]} {rest}.'.replace('  ', ' ')
    return (' '.join(words).capitalize() + '.')


def indent_of(line):
    return line[:len(line) - len(line.lstrip())]


def parse_params(sig):
    """Zerlegt eine Parameterliste in (Typ, Name, hat_default)."""
    inner = sig[sig.find('(') + 1:]
    depth, buf, parts = 1, '', []
    for ch in inner:
        if ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
            if depth == 0:
                break
        if depth == 1 and ch == ',':
            parts.append(buf)
            buf = ''
        else:
            buf += ch
    if buf.strip():
        parts.append(buf)
    out = []
    for part in parts:
        part = part.strip()
        if not part:
            continue
        default = '=' in part
        decl = part.split('=')[0].strip()
        tokens = decl.replace('&', ' ').replace('...', ' ').split()
        varname = next((t for t in tokens if t.startswith('$')), None)
        if varname is None:
            continue
        typetokens = [t for t in tokens if not t.startswith('$')
                      and t not in ('public', 'private', 'protected', 'readonly')]
        phptype = typetokens[-1] if typetokens else 'mixed'
        out.append((phptype.lstrip('?'), varname, default))
    return out


def collect_signature(lines, idx):
    """Sammelt eine ueber mehrere Zeilen verteilte Signatur bis zur schliessenden Klammer."""
    sig, depth = '', 0
    for line in lines[idx:idx + 30]:
        sig += line
        depth += line.count('(') - line.count(')')
        if '(' in sig and depth <= 0:
            break
    return sig


def function_docblock(lines, idx):
    line = lines[idx]
    pad = indent_of(line)
    name = re.search(r'function\s+&?(\w+)', line)
    if not name:
        return None
    sig = collect_signature(lines, idx)
    params = parse_params(sig)
    rettype = re.search(r'\)\s*:\s*([^\s{;]+)', sig)
    doc = [f'{pad}/**', f'{pad} * {humanise(name.group(1))}']
    if params or rettype:
        doc.append(f'{pad} *')
    for phptype, varname, default in params:
        desc = humanise(varname.lstrip('$')).rstrip('.')
        if default:
            desc += ', optional'
        doc.append(f'{pad} * @param {phptype} {varname} {desc}.')
    if rettype:
        ret = rettype.group(1).lstrip('?')
        if ret not in ('void', 'never'):
            doc.append(f'{pad} * @return {ret}')
    doc.append(f'{pad} */')
    return doc


def class_docblock(lines, idx):
    line = lines[idx]
    pad = indent_of(line)
    name = re.search(r'(?:class|interface|trait|enum)\s+(\w+)', line)
    if not name:
        return None
    return [f'{pad}/**', f'{pad} * {humanise(name.group(1))}', f'{pad} */']


def constant_docblock(lines, idx):
    line = lines[idx]
    pad = indent_of(line)
    name = re.search(r'const\s+(\w+)', line)
    if not name:
        return None
    return [f'{pad}/** {humanise(name.group(1).lower())} */']


def variable_docblock(lines, idx):
    line = lines[idx]
    pad = indent_of(line)
    match = re.search(
        r'(?:public|protected|private)\s+(?:static\s+)?(?:readonly\s+)?([\w\\|?]+)?\s*(\$\w+)', line)
    if not match:
        return None
    phptype = (match.group(1) or 'mixed').lstrip('?')
    varname = match.group(2)
    return [f'{pad}/** @var {phptype} {varname} {humanise(varname.lstrip("$"))} */']


def add_description(lines, idx):
    """Ergaenzt die fehlende Einzeilenbeschreibung in einem vorhandenen Docblock."""
    # Der Docblock beginnt auf oder oberhalb der gemeldeten Zeile.
    start = idx
    while start >= 0 and '/**' not in lines[start]:
        start -= 1
    if start < 0:
        return False
    pad = indent_of(lines[start])
    subject = None
    for look in range(idx, min(idx + 40, len(lines))):
        found = re.search(r'function\s+&?(\w+)', lines[look])
        if found:
            subject = humanise(found.group(1))
            break
        found = re.search(r'(?:class|interface|trait)\s+(\w+)', lines[look])
        if found:
            subject = humanise(found.group(1))
            break
    if subject is None:
        subject = 'Adaptive quiz activity module.'
    lines.insert(start + 1, f'{pad} * {subject}\n{pad} *\n')
    return True


def drop_inheritdoc(lines, idx):
    """Ersetzt einen reinen @inheritDoc-Docblock durch das Attribut #[\\Override]."""
    start = idx
    while start >= 0 and '/**' not in lines[start]:
        start -= 1
    end = idx
    while end < len(lines) and '*/' not in lines[end]:
        end += 1
    if start < 0 or end >= len(lines):
        return False
    body = ''.join(lines[start:end + 1])
    if re.sub(r'[\s*/]|@inheritDoc', '', body, flags=re.I) != '':
        return False  # Docblock enthaelt mehr als nur den Tag - Finger weg.
    pad = indent_of(lines[start])
    lines[start:end + 1] = [f'{pad}#[\\Override]\n']
    return True


def capitalise_comment(lines, idx):
    line = lines[idx]
    match = re.match(r'(\s*//\s*)(\w)(.*)', line)
    if not match:
        return False
    lines[idx] = match.group(1) + match.group(2).upper() + match.group(3)
    return True


def main():
    report = json.load(open(sys.argv[1]))
    stats = {}
    for path, data in report['files'].items():
        issues = [m for m in data['messages'] if m['source'] in HANDLED]
        if not issues:
            continue
        lines = open(path).readlines()
        for msg in sorted(issues, key=lambda m: -m['line']):
            idx = msg['line'] - 1
            src = msg['source']
            block = None
            if src == 'moodle.Commenting.MissingDocblock.Function':
                block = function_docblock(lines, idx)
            elif src in ('moodle.Commenting.MissingDocblock.Class',
                         'moodle.Commenting.MissingDocblock.Interface'):
                block = class_docblock(lines, idx)
            elif src == 'moodle.Commenting.MissingDocblock.Constant':
                block = constant_docblock(lines, idx)
            elif src == 'Squiz.Commenting.VariableComment.Missing':
                block = variable_docblock(lines, idx)
            elif src == 'moodle.Commenting.DocblockDescription.Missing':
                if add_description(lines, idx):
                    stats[src] = stats.get(src, 0) + 1
                continue
            elif src == 'moodle.Commenting.ValidTags.Invalid':
                if drop_inheritdoc(lines, idx):
                    stats[src] = stats.get(src, 0) + 1
                continue
            elif src == 'Squiz.Commenting.InlineComment.NotCapital':
                if capitalise_comment(lines, idx):
                    stats[src] = stats.get(src, 0) + 1
                continue
            if block:
                lines.insert(idx, ''.join(line + '\n' for line in block))
                stats[src] = stats.get(src, 0) + 1
        open(path, 'w').write(''.join(lines))
    for key in sorted(stats):
        print(f'  {stats[key]:4d}  {key}')


if __name__ == '__main__':
    main()
