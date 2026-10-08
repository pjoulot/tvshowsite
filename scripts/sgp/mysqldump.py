"""Minimal reader for the INSERT statements of a mysqldump file."""
import re

ESC = {'0': '\0', 'b': '\b', 'n': '\n', 'r': '\r', 't': '\t', 'Z': '\x1a', '\\': '\\', "'": "'", '"': '"'}


def parse_values(s, i):
    """Parses '(...),(...);' starting at s[i]; returns rows and end index."""
    rows = []
    n = len(s)
    while i < n:
        c = s[i]
        if c == '(':
            row = []
            i += 1
            while True:
                c = s[i]
                if c == "'":
                    i += 1
                    buf = []
                    while True:
                        c = s[i]
                        if c == '\\':
                            nxt = s[i + 1]
                            buf.append(ESC.get(nxt, '\\' + nxt) if nxt not in '%_' else '\\' + nxt)
                            i += 2
                        elif c == "'":
                            if s[i + 1] == "'":
                                buf.append("'")
                                i += 2
                            else:
                                i += 1
                                break
                        else:
                            buf.append(c)
                            i += 1
                    row.append(''.join(buf))
                else:
                    m = re.compile(r'[^,)]*').match(s, i)
                    tok = m.group(0).strip()
                    i = m.end()
                    row.append(None if tok == 'NULL' else tok)
                c = s[i]
                if c == ',':
                    i += 1
                    continue
                if c == ')':
                    i += 1
                    break
            rows.append(row)
        elif c == ';':
            return rows, i + 1
        else:
            i += 1
    return rows, i


def read_dump(path):
    import gzip
    opener = gzip.open if path.endswith('.gz') else open
    with opener(path, 'rt', encoding='utf-8') as handle:
        s = handle.read()
    tables = {}
    columns = {}
    for m in re.finditer(r'CREATE TABLE `(\w+)` \((.*?)\n\)', s, re.S):
        columns[m.group(1)] = re.findall(r'^\s*`(\w+)`', m.group(2), re.M)
    for m in re.finditer(r'INSERT INTO `(\w+)` VALUES ', s):
        rows, _ = parse_values(s, m.end())
        cols = columns.get(m.group(1))
        tables.setdefault(m.group(1), []).extend(dict(zip(cols, r)) if cols else r for r in rows)
    return tables


def stripslashes(text):
    """The old site stored text through addslashes()."""
    if text is None:
        return ''
    return re.sub(r'\\(.)', r'\1', text, flags=re.S)
