#!/usr/bin/env python3
"""Ergaenzt fehlende @param-Eintraege und normalisiert kaputte.

Der Moodle-PHPDoc-Checker meldet eine Parameterliste als unvollstaendig, wenn ein
Parameter der Signatur keinen @param-Eintrag hat - oder wenn der Eintrag nicht
parsbar ist, etwa "@param string $feature: Beschreibung" mit Doppelpunkt hinter
dem Variablennamen.

Aufruf:
    moodle-plugin-ci phpdoc --max-warnings 0 <plugin> > /tmp/pd.log
    python3 tools/phpdoc_params.py /tmp/pd.log <pluginwurzel>
"""

import re
import sys


def parse_report(path):
    """Liest den Bericht und liefert {datei: [funktionsnamen]}."""
    result = {}
    current = None
    for line in open(path, encoding='utf-8', errors='replace'):
        stripped = line.strip()
        if stripped.startswith('/') and stripped.endswith('.php'):
            current = stripped
            continue
        found = re.search(r'Phpdocs for function ([\w:]+) has incomplete parameters list', stripped)
        if found and current:
            name = found.group(1).split('::')[-1]
            result.setdefault(current, []).append(name)
    return result


def signature_params(lines, idx):
    """Zerlegt die Signatur ab Zeile idx in [(typ, name)]."""
    sig, depth = '', 0
    for line in lines[idx:idx + 30]:
        sig += line
        depth += line.count('(') - line.count(')')
        if '(' in sig and depth <= 0:
            break
    inner = sig[sig.find('(') + 1:sig.rfind(')')]
    depth, buf, parts = 0, '', []
    for ch in inner:
        if ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        if depth == 0 and ch == ',':
            parts.append(buf)
            buf = ''
        else:
            buf += ch
    if buf.strip():
        parts.append(buf)

    out = []
    for part in parts:
        decl = part.split('=')[0].strip()
        tokens = decl.replace('&', ' ').replace('...', ' ').split()
        varname = next((t for t in tokens if t.startswith('$')), None)
        if varname is None:
            continue
        types = [t for t in tokens
                 if not t.startswith('$') and t not in ('public', 'private', 'protected', 'readonly')]
        out.append((types[-1] if types else 'mixed', varname))
    return out


def docblock_bounds(lines, idx):
    """Liefert (start, ende) des Docblocks ueber Zeile idx, sonst None."""
    scan = idx - 1
    while scan >= 0 and lines[scan].strip() in ('', '#[\\Override]') or (
            scan >= 0 and lines[scan].strip().startswith('#[')):
        scan -= 1
    if scan < 0 or not lines[scan].strip().endswith('*/'):
        return None
    end = scan
    start = end
    while start >= 0 and '/**' not in lines[start]:
        start -= 1
    return None if start < 0 else (start, end)


def repair(path, names):
    lines = open(path, encoding='utf-8').readlines()
    changed = False

    for name in dict.fromkeys(names):
      pattern = re.compile(r'function\s+&?' + re.escape(name) + r'\s*\(')
      for idx in [i for i, l in enumerate(lines) if pattern.search(l)][::-1]:

          bounds = docblock_bounds(lines, idx)
          if bounds is None:
              continue
          start, end = bounds

          pad = lines[start][:len(lines[start]) - len(lines[start].lstrip())]
          params = signature_params(lines, idx)

          # Kaputte Eintraege normalisieren: Doppelpunkt hinter dem Variablennamen.
          for i in range(start, end + 1):
              lines[i] = re.sub(r'(@param\s+\S+\s+\$\w+):', r'\1', lines[i])

          # Die @param-Liste vollstaendig aus der Signatur neu aufbauen. Der Moodle-Checker
          # vergleicht Typ und Name positionsgenau; eine Teilreparatur trifft das selten.
          existing = {}
          keep = []
          for i in range(start, end + 1):
              found = re.search(r'@param\s+(\S+)\s+(\$\w+)\s*(.*)', lines[i])
              if found:
                  existing[found.group(2)] = (found.group(1), found.group(3).strip())
              elif '@param' not in lines[i]:
                  keep.append(lines[i])

          block = []
          for phptype, varname in params:
              doctype, desc = existing.get(varname, ('', ''))
              usetype = phptype if phptype != 'mixed' else (doctype or 'mixed')
              if not desc:
                  words = re.sub(r'(?<!^)(?=[A-Z])', '_', varname.lstrip('$')).lower().split('_')
                  desc = ' '.join(w for w in words if w).capitalize() + '.'
              block.append(f'{pad} * @param {usetype} {varname} {desc}\n')

          # Einfuegen, wo die alte Liste stand: vor @return, sonst vor dem Docblockende.
          insertat = len(keep) - 1
          for i, l in enumerate(keep):
              if '@return' in l:
                  insertat = i
                  break
          keep[insertat:insertat] = block
          lines[start:end + 1] = keep
          changed = True

    if changed:
        open(path, 'w', encoding='utf-8').write(''.join(lines))
    return changed


def main():
    report, root = sys.argv[1], sys.argv[2].rstrip('/')
    files = parse_report(report)
    count = 0
    for reported, names in files.items():
        local = root + '/' + reported.split('adaptivequiz/')[-1]
        try:
            if repair(local, names):
                count += 1
        except FileNotFoundError:
            print('  Datei fehlt:', local)
    print('Dateien bearbeitet:', count)


if __name__ == '__main__':
    main()
