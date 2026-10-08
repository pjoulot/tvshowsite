"""Splits a cleaned old page into picture, facts and text."""
import re

from oldsite import text

MONTHS = {m: i for i, m in enumerate(['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'], 1)}
MONTHS['fevrier'] = 2
MONTHS['aout'] = 8
MONTHS['decembre'] = 12

# Labels that open a block of text rather than give a short fact.
SECTIONS = {
    'description', 'synopsis', 'histoire', 'cast principal', 'casting', 'contenu', 'présentation', 'presentation',
    'informations supplémentaires', 'guest stars', 'biographie', 'filmographie', 'résumé', 'note',
}


def french_date(value):
    """'le 11 février 2005' -> '2005-02-11'."""
    m = re.search(r'(\d{1,2})(?:er)?\s+([a-zéû]+)\s+(\d{4})', (value or '').lower())
    if not m or m.group(2) not in MONTHS:
        return None
    return '%04d-%02d-%02d' % (int(m.group(3)), MONTHS[m.group(2)], int(m.group(1)))


def minutes(value):
    """'1h 24min 29s' -> 84, '41min 54s' -> 42."""
    value = (value or '').lower()
    h = re.search(r'(\d+)\s*h', value)
    m = re.search(r'(\d+)\s*min', value)
    s = re.search(r'(\d+)\s*s\b', value)
    if not (h or m):
        return None
    total = (int(h.group(1)) * 60 if h else 0) + (int(m.group(1)) if m else 0) + (1 if s and int(s.group(1)) >= 30 else 0)
    return total or None


LABEL = re.compile(r'^\s*<strong>\s*([^<]{2,60}?)\s*:?\s*</strong>\s*:?\s*(.*)$', re.S)


def split(html):
    """Returns (picture, facts, sections_html).

    picture: archive path of the first picture when the page opens with it.
    facts: ordered list of (label, value_html).
    """
    picture = None
    blocks = re.split(r'\n(?=<(?:p|h2|h3|h4|ul|ol|table|blockquote|div|figure|dl)\b)', html.strip())
    # Opening picture.
    if blocks:
        m = re.match(r'^<p[^>]*>\s*(?:<a [^>]*>)?\s*<img [^>]*src="(archive:[^"]+)"[^>]*/?>\s*(?:</a>)?\s*(?:<br>\s*)*', blocks[0])
        if m:
            picture = m.group(1)[len('archive:'):]
            rest = blocks[0][m.end():]
            rest = re.sub(r'^(\s*<br>)+', '', rest)
            if re.sub(r'<[^>]+>|\s', '', rest.replace('</p>', '')):
                blocks[0] = '<p>' + rest
            else:
                blocks.pop(0)
    facts = []
    out = []
    for block in blocks:
        m = re.match(r'^<p([^>]*)>(.*)</p>$', block, re.S)
        if not m:
            out.append(block)
            continue
        lines = re.split(r'\s*<br>\s*', m.group(2).strip())
        labelled = [LABEL.match(line) for line in lines]
        if not labelled[0]:
            out.append(block)
            continue
        section = None
        buffer = []

        def close():
            if section is not None:
                body = '<br>'.join(x for x in buffer if x.strip())
                out.append('<h2>%s</h2>' % section)
                if re.sub(r'<[^>]+>|\s', '', body) or '<img' in body:
                    out.append('<p>%s</p>' % body)

        for line, lm in zip(lines, labelled):
            if lm and (section is None or lm.group(1).strip().lower() in SECTIONS or len(lm.group(2)) < 200):
                label = lm.group(1).strip().rstrip(':').strip()
                if label.lower() in SECTIONS:
                    close()
                    section = label[0].upper() + label[1:]
                    buffer = [lm.group(2)]
                    continue
                if section is not None:
                    # A fact after a section: the section is over.
                    close()
                    section = None
                    buffer = []
                facts.append((label, lm.group(2).strip()))
            elif section is not None:
                buffer.append(line)
            else:
                # Plain text inside a facts paragraph.
                section = ''
                buffer = [line]
        if section == '':
            out.append('<p>%s</p>' % '<br>'.join(buffer))
        else:
            close()
    html = '\n'.join(out)
    html = re.sub(r'<h2></h2>\n?', '', html)
    return picture, facts, html


def fact_text(value_html):
    return text(value_html)


def facts_lines(facts, skip=()):
    lines = []
    for label, value in facts:
        if label.lower() in skip:
            continue
        value = fact_text(value)
        if value and value.lower() not in ('indisponible', '?'):
            lines.append('%s : %s' % (label, value))
    return '\n'.join(lines)
