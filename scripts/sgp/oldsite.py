"""Readers for the old stargate-pegasus.com PHP site.

The old site is a set of PHP dispatchers (one per section) that pick a
template from Templates/Pages from the query string, behind ~170 Apache
rewrite rules. routes() replays that logic to list every public address
with the template, title and breadcrumb it showed.
"""
import html as htmlmod
import os
import re

from bs4 import BeautifulSoup, NavigableString, Tag


class OldSite:

    def __init__(self, root):
        self.root = root.rstrip('/')

    def read(self, relative):
        data = open(os.path.join(self.root, relative), 'rb').read()
        try:
            return data.decode('utf-8')
        except UnicodeDecodeError:
            return data.decode('cp1252', errors='replace')

    def exists(self, relative):
        return os.path.isfile(os.path.join(self.root, relative))

    # ---- Routing ----------------------------------------------------------

    @staticmethod
    def _php_string(value):
        value = value.strip()
        m = re.match(r'^(?:title|arbre)\((.*)\)$', value, re.S)
        if m:
            value = m.group(1).strip()
        if value[:1] == "'":
            return value[1:-1].replace("\\'", "'")
        if value[:1] == '"':
            return value[1:-1].replace('\\"', '"')
        return value

    def dispatch(self, php):
        """Every template a dispatcher can show, with the conditions leading to it."""
        stack, records, current = [], [], {}
        for line in self.read(php).split('\n'):
            line = line.strip()
            m = re.match(r"^(\})?\s*(else)?if\s*\(\s*\$_GET\['(\w+)'\]\s*==\s*'?(\w+)'?\s*\)\s*\{", line)
            if m:
                if m.group(1) and stack:
                    stack.pop()
                stack.append((m.group(3), m.group(4)))
                current = {}
                continue
            m = re.match(r'^(\})?\s*else\s*\{', line)
            if m:
                if m.group(1) and stack:
                    stack.pop()
                stack.append(('else', ''))
                current = {}
                continue
            if line.startswith('}'):
                if stack:
                    stack.pop()
                continue
            m = re.match(r'^\$(title|titrePage|arbre|fic)\s*=\s*(.*);\s*$', line)
            if m:
                current[m.group(1)] = self._php_string(m.group(2))
                if m.group(1) == 'fic':
                    record = dict(current)
                    record['cond'] = {k: v for k, v in stack if k != 'else'}
                    records.append(record)
        return records

    def routes(self):
        rules = []
        for line in self.read('.htaccess').split('\n'):
            m = re.match(r'^\s*RewriteRule\s+\^(\S+)\$\s+/(\S+?\.php)(\?\S*)?\s', line)
            if m:
                rules.append((m.group(1), m.group(2), m.group(3) or ''))
        out = []
        for pattern, php, query in rules:
            if not self.exists(php):
                continue
            params = dict(re.findall(r'(\w+)=\$(\d)', query))
            if not params:
                url = pattern.replace('\\', '')
                if not re.search(r'[(\[]', url):
                    out.append({'url': '/' + url, 'php': php})
                continue
            for record in self.dispatch(php):
                groups = {}
                if any(k not in record['cond'] for k in params):
                    continue
                for k, g in params.items():
                    groups[g] = record['cond'][k]
                counter = [0]

                def replace(_m):
                    counter[0] += 1
                    return groups.get(str(counter[0]), '')

                url = re.sub(r'\([^()]*\)\??', replace, pattern).replace('\\', '')
                record = dict(record, url='/' + url, php=php)
                out.append(record)
        return out

    # ---- Templates --------------------------------------------------------

    def page(self, template):
        """The content part of a page template (between the title and the footer)."""
        text = self.read('Templates/Pages/' + template)
        start = text.find('{titrePage}</h1>')
        start = start + len('{titrePage}</h1>') if start >= 0 else 0
        end = text.find('<include file="footer.tpl"')
        body = text[start:end if end > 0 else None]
        body = re.sub(r'</div>\s*$', '', body.rstrip())
        return body


# ---- HTML clean-up ---------------------------------------------------------

SITE_HOSTS = ('www.stargate-pegasus.com', 'stargate-pegasus.com')


def link(href):
    """Rewrites an address of the old site for the content pack."""
    if not href:
        return href
    href = href.strip()
    if href in ('/chat', 'chat', '/forum'):
        # Features of the old site that are gone.
        return 'legacy:' + ('/' + href.lstrip('/'))
    m = re.match(r'^(?:https?://(?:www\.)?stargate-pegasus\.com)?/?([\w.\-]+\.html)(#.*)?$', href)
    if m:
        return 'legacy:/' + m.group(1) + (m.group(2) or '')
    m = re.match(r'^(?:https?://(?:www\.)?stargate-pegasus\.com)?/?(?:\.\./)*(Templates/.+)$', href)
    if m:
        return 'archive:' + m.group(1)
    if href.startswith('images/'):
        return 'archive:Templates/Images/' + href[len('images/'):]
    if href.startswith('//'):
        return 'https:' + href
    return href


KEEP = {'p', 'br', 'a', 'img', 'strong', 'em', 'u', 's', 'sup', 'sub', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'figure', 'figcaption', 'div', 'span', 'iframe', 'dl', 'dt', 'dd'}


def youtube_id(src):
    m = re.search(r'(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|v/))([A-Za-z0-9_-]{11})', src or '')
    return m.group(1) if m else None


def clean(fragment):
    """Old markup to plain, class-free HTML the new theme styles."""
    soup = BeautifulSoup('<div id="root">' + fragment + '</div>', 'html.parser')
    root = soup.find(id='root')
    for tag in root.find_all('img'):
        classes = ' '.join(tag.get('class') or [])
        parent = ' '.join(tag.parent.get('class') or []) if isinstance(tag.parent, Tag) else ''
        if 'Gauche' in classes or 'flottantGauche' in parent or 'imageflottante' in classes or 'imageflottante' in parent:
            tag['data-float'] = 'left'
        elif 'Droite' in classes or 'flottantDroite' in parent:
            tag['data-float'] = 'right'
    for tag in root.find_all('span', class_='grand'):
        tag.name = 'h2'
        tag.attrs = {}
        for inner in tag.find_all(True):
            inner.unwrap()
    for tag in root.find_all(['script', 'style', 'form', 'textarea', 'canvas', 'input', 'noscript']):
        tag.decompose()
    for tag in root.find_all(True):
        classes = tag.get('class') or []
        if tag.name == 'b':
            tag.name = 'strong'
        elif tag.name == 'i':
            tag.name = 'em'
        elif tag.name == 'span' and ('gras' in classes or 'titreep' in classes):
            tag.name = 'strong'
        elif tag.name == 'span' and 'italique' in classes:
            tag.name = 'em'
        elif tag.name == 'span' and 'souligner' in classes:
            tag.name = 'u'
        elif tag.name == 'h1':
            tag.name = 'h2'
        elif tag.name == 'center':
            tag.name = 'p'
        if tag.name == 'iframe':
            vid = youtube_id(tag.get('src'))
            if vid:
                new = soup.new_tag('div')
                new['class'] = 'video-embed'
                new['data-youtube'] = vid
                new['data-title'] = 'Lire la vidéo YouTube'
                tag.replace_with(new)
                continue
            tag.decompose()
            continue
        if tag.name == 'img':
            attrs = {'src': link(tag.get('src', '')), 'alt': (tag.get('alt') or '').strip()}
            if attrs['src'].startswith(('http:', 'https:')):
                # Pictures hotlinked from other sites: mostly gone, and
                # blocked as mixed content on an https site.
                tag.decompose()
                continue
            if tag.get('data-float'):
                attrs['class'] = 'align-' + tag['data-float']
            tag.attrs = attrs
            continue
        if tag.name == 'a':
            href = link(tag.get('href', ''))
            tag.attrs = {'href': href} if href else {}
            continue
        if tag.name not in KEEP:
            tag.unwrap()
            continue
        keep = {}
        if tag.name == 'div' and tag.get('data-youtube'):
            continue
        if tag.name in ('td', 'th') and tag.get('colspan'):
            keep['colspan'] = tag['colspan']
        if 'centrer' in classes and tag.name in ('p', 'div'):
            keep['class'] = 'text-align-center'
        tag.attrs = keep
    # Layout wrappers of the old site carry nothing once classes are gone.
    for tag in list(root.find_all(['div', 'span'])):
        if not tag.attrs:
            tag.unwrap()
    out = root.decode_contents()
    return paragraphs(out)


BLOCK = re.compile(r'^\s*<(p|h[2-4]|ul|ol|table|blockquote|figure|div|dl)\b', re.I)


def paragraphs(markup):
    """Turns text separated by <br><br> into paragraphs."""
    markup = re.sub(r'<br\s*/?>', '<br>', markup)
    markup = re.sub(r'[ \t]+', ' ', markup)
    markup = re.sub(r'\s*\n\s*', '\n', markup)
    # Split into blocks on double breaks and on block-level tags.
    soup = BeautifulSoup('<div id="r">' + markup + '</div>', 'html.parser').find(id='r')
    blocks, inline = [], []

    def flush():
        text = ''.join(str(x) for x in inline)
        text = re.sub(r'<br\s*/?>', '<br>', text)
        for part in re.split(r'(?:\s*<br>\s*){2,}', text):
            part = re.sub(r'^(\s*<br>\s*)+|(\s*<br>\s*)+$', '', part).strip()
            if re.sub(r'<[^>]+>|&nbsp;|\s', '', part) or '<img' in part or 'video-embed' in part:
                if re.fullmatch(r'<img[^>]*>', part) or 'video-embed' in part and part.startswith('<div'):
                    blocks.append(part if part.startswith('<div') else '<p>' + part + '</p>')
                else:
                    blocks.append('<p>' + part + '</p>')
        inline.clear()

    for child in list(soup.children):
        if isinstance(child, Tag) and (child.name in ('p', 'h2', 'h3', 'h4', 'ul', 'ol', 'table', 'blockquote', 'figure', 'dl') or (child.name == 'div' and (child.get('class') or child.get('data-youtube')))):
            flush()
            if child.name == 'p':
                inner = child.decode_contents()
                cls = ' class="text-align-center"' if child.get('class') else ''
                for part in re.split(r'(?:\s*<br/?>\s*){2,}', inner):
                    part = re.sub(r'^(\s*<br/?>\s*)+|(\s*<br/?>\s*)+$', '', part).strip()
                    if re.sub(r'<[^>]+>|&nbsp;|\s', '', part) or '<img' in part:
                        blocks.append('<p%s>%s</p>' % (cls, part))
            else:
                blocks.append(str(child))
        else:
            inline.append(child)
    flush()
    out = '\n'.join(blocks)
    out = re.sub(r'<br\s*/>', '<br>', out)
    # A paragraph that is only a bold line is a sub-heading.
    out = re.sub(r'<p><strong>([^<]{2,120})</strong>\s*:?\s*</p>', r'<h3>\1</h3>', out)
    # The same, with the picture of the next paragraph in front of it.
    out = re.sub(r'<p>(<img [^>]*class="align-(?:left|right)"[^>]*/?>)\s*<strong>([^<]{2,120})</strong>\s*:?\s*</p>\n<p>', r'<h3>\2</h3>\n<p>\1', out)
    # Pictures floated left or right sit at the start of the paragraph they illustrate.
    out = re.sub(r'<p>(<img [^>]*class="align-(?:left|right)"[^>]*/?>)</p>\n<p>', r'<p>\1', out)
    return out


def text(fragment):
    """Plain text of a fragment."""
    t = BeautifulSoup(fragment, 'html.parser').get_text(' ')
    return re.sub(r'\s+', ' ', htmlmod.unescape(t)).strip()


# ---- BBCode of the news ----------------------------------------------------

def picture(src, alt):
    src = link(src.strip())
    # Pictures hotlinked from other sites are left out (see clean()).
    return '' if src.startswith(('http:', 'https:')) else '<img src="%s" alt="%s">' % (src, alt)


def bbcode(source):
    """The old site's BBCode (Includes/fonctions.php) to HTML."""
    s = htmlmod.escape(source.replace('\r\n', '\n').replace('\r', '\n'), quote=False)
    s = s.replace('&amp;', '&')
    rules = [
        (r'\[gras\](.+?)\[/gras\]', r'<strong>\1</strong>'),
        (r'\[italique\](.+?)\[/italique\]', r'<em>\1</em>'),
        (r'\[souligner\](.+?)\[/souligner\]', r'<u>\1</u>'),
        (r'\[barrer\](.+?)\[/barrer\]', r'<s>\1</s>'),
        (r'\[citation auteur="(.+?)"\](.+?)\[/citation\]', r'<blockquote><p><strong>Citation de \1 :</strong></p>\2</blockquote>'),
        (r'\[citation\](.+?)\[/citation\]', r'<blockquote>\1</blockquote>'),
        (r'\[image nom="(.+?)"\](.+?)\[/image\]', lambda m: picture(m.group(2), m.group(1))),
        (r'\[image\](.+?)\[/image\]', lambda m: picture(m.group(1), '')),
        (r'\[icone nom="(.+?)"\](.+?)\[/icone\]', lambda m: '<a href="%s"><img src="%s" alt="%s" class="tv-icon"></a>' % (link(m.group(2).strip()), link(m.group(2).strip()), m.group(1))),
        (r'\[lien url="(.+?)"\](.+?)\[/lien\]', lambda m: '<a href="%s">%s</a>' % (link(m.group(1).strip()), m.group(2))),
        (r'\[lien\](.+?)\[/lien\]', lambda m: '<a href="%s">%s</a>' % (link(m.group(1).strip()), m.group(1))),
        (r'\[email adresse="(.+?)"\](.+?)\[/email\]', r'<a href="mailto:\1">\2</a>'),
        (r'\[email\](.+?)\[/email\]', r'<a href="mailto:\1">\1</a>'),
        (r'\[youtube\](.+?)\[/youtube\]', lambda m: '\n\n<div class="video-embed" data-youtube="%s" data-title="Lire la vidéo YouTube"></div>\n\n' % (youtube_id(m.group(1)) or '')),
        (r'\[position valeur="centrer"\](.+?)\[/position\]', r'\n\n<p class="text-align-center">\1</p>\n\n'),
        (r'\[position valeur="(.+?)"\](.+?)\[/position\]', r'\1'),
        (r'\[taille valeur="(?:grand|tgrand|ttgrand)"\](.+?)\[/taille\]', r'<strong>\1</strong>'),
        (r'\[taille valeur="(.+?)"\](.+?)\[/taille\]', r'\2'),
        (r'\[flottant valeur="(\w+)"\](.+?)\[/flottant\]', lambda m: m.group(2).replace('<img ', '<img class="align-%s" ' % ('left' if m.group(1).lower().startswith('g') else 'right'), 1)),
        (r'\[couleur valeur="(.+?)"\](.+?)\[/couleur\]', r'\2'),
        (r'\[police valeur="(.+?)"\](.+?)\[/police\]', r'\2'),
        (r'\[exposant\](.+?)\[/exposant\]', r'<sup>\1</sup>'),
        (r'\[indice\](.+?)\[/indice\]', r'<sub>\1</sub>'),
        (r'\[wikipedia type="(fr|en)"\](.+?)\[/wikipedia\]', r'<a href="https://\1.wikipedia.org/wiki/\2">\2</a>'),
    ]
    for _ in range(3):
        for pattern, replacement in rules:
            s = re.sub(pattern, replacement, s, flags=re.S | re.I)
    # Paragraphs: blank lines split, single newlines break.
    blocks = []
    for part in re.split(r'\n\s*\n', s.strip()):
        part = part.strip()
        if not part:
            continue
        if re.match(r'^<(p|div|blockquote)\b', part) and re.search(r'</(p|div|blockquote)>$', part):
            blocks.append(part.replace('\n', '<br>'))
        else:
            blocks.append('<p>' + part.replace('\n', '<br>') + '</p>')
    out = '\n'.join(blocks)
    out = re.sub(r'<p>(\s|<br>)*</p>', '', out)
    out = re.sub(r'<p>((?:<br>|\s)*)', '<p>', out)
    out = re.sub(r'(?:<br>|\s)*</p>', '</p>', out)
    return out
