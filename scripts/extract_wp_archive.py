#!/usr/bin/env python3
"""Extract content from the static WordPress archive of stargateuniverse.fr.

Usage: extract_wp_archive.py <archive_dir> <episodes.json> <output.json> [episodes_extra.json]

Reads the Wayback Machine copy (a tree of index.html files plus wp-content)
and writes one JSON content pack that the Drupal importer
(tvshow_import module) loads. Image references are paths relative to the
archive root; the importer copies the files at import time.
Reader comments are deliberately not extracted.
"""
import json
import os
import re
import sys
import unicodedata
from bs4 import BeautifulSoup, Comment, NavigableString

ARCHIVE, EPISODES_JSON, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
EXTRA_JSON = sys.argv[4] if len(sys.argv) > 4 else None
HOSTS = ('http://stargateuniverse.lndo.site', 'https://stargateuniverse.lndo.site',
         'http://www.stargateuniverse.fr', 'http://stargateuniverse.fr',
         'https://www.stargateuniverse.fr', 'https://stargateuniverse.fr')
MONTHS = {'janvier': 1, 'février': 2, 'fevrier': 2, 'mars': 3, 'avril': 4, 'mai': 5, 'juin': 6, 'juillet': 7,
          'août': 8, 'aout': 8, 'septembre': 9, 'octobre': 10, 'novembre': 11, 'décembre': 12, 'decembre': 12}
SKIP_DIRS = {'.git', '2009', '2010', '2011', 'author', 'category', 'tag', 'comments', 'feed', 'wp-admin',
             'wp-content', 'wp-includes', 'nggallery', 'phpbb3', 'episodes', 'equipe', 'galerie', 'la-serie',
             'videos', '180'}
ALLOWED = {'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'a', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'h4',
           'img', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'div', 'hr', 'sup', 'sub', 'del'}
IMG_EXT = ('.jpg', '.jpeg', '.png', '.gif')


def slugify(text):
    text = unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')


def local_path(url):
    """Archive-relative path for a site URL, or None for external URLs."""
    if not url:
        return None
    url = url.strip()
    for h in HOSTS:
        if url.startswith(h):
            url = url[len(h):]
            break
    else:
        if re.match(r'^[a-z]+:', url) or url.startswith('//'):
            return None
    return url.split('#')[0].split('?')[0]


def existing_image(url):
    p = local_path(url)
    if not p:
        return None
    p = p.lstrip('/')
    if p.lower().endswith(IMG_EXT) and '/thumbs/' not in p and os.path.isfile(os.path.join(ARCHIVE, p)):
        return p
    return None


def youtube_id(html):
    m = re.search(r'youtube(?:-nocookie)?\.com/(?:v|embed)/([A-Za-z0-9_-]{11})', html)
    return m.group(1) if m else None


CAST_GALLERY = {
    'nicholas-rush': 'carlyle_as_nicholas_rush', 'everett-young': 'ferreira_as_everettyoung', 'eli-wallace': 'blue_as_eli_wallace',
    'matthew-scott': 'smith_as_matthew_scott', 'ronald-greer': 'jamil_smith_as_ronald_greer', 'chloe-armstrong': 'levesque_as_chloe_armstrong',
    'tamara-johansen': 'huffman_as_tamara_johansen', 'camille-wray': 'mingna_as_camile_wray', 'colonel-telford': 'lou_diamond_phillips_as_telford',
}
# English episode titles distinctive enough to identify an episode in a post address.
TITLE_WORDS = {
    (1, 4): ['darkness'], (1, 6): ['water'], (1, 7): ['earth'], (1, 10): ['justice'], (1, 12): ['divided'], (1, 13): ['faith'],
    (1, 14): ['human'], (1, 15): ['lost'], (1, 16): ['sabotage'], (1, 17): ['pain'], (1, 18): ['subversion'], (1, 19): ['incursion'],
    (2, 1): ['intervention'], (2, 2): ['aftermath'], (2, 3): ['awakenings', 'awakening'], (2, 4): ['pathogen'], (2, 5): ['cloverdale'],
    (2, 6): ['trial-and-error'], (2, 7): ['greater-good'], (2, 8): ['malice'], (2, 9): ['visitation'], (2, 10): ['resurgence'],
    (2, 11): ['deliverance'], (2, 12): ['twin-destinies'], (2, 13): ['alliances'], (2, 14): ['hope'], (2, 15): ['seizure'],
    (2, 16): ['the-hunt'], (2, 17): ['common-descent'], (2, 18): ['epilogue'], (2, 19): ['blockade'], (2, 20): ['gauntlet'],
}


def episode_of_post(post):
    """(season, number) a news post is about, or None when unclear."""
    slug = slugify(post['slug'])
    if re.search(r'episodes?-\d+-(?:et-|a-)?\d+|episodes-1112|-et-\d+$', slug):
        return None
    m = re.search(r'saison-(\d)-episode-(\d+)', slug)
    if m:
        return int(m.group(1)), int(m.group(2))
    hits = {key for key, words in TITLE_WORDS.items() for w in words if re.search(r'(^|-)%s(-|$)' % w, slug)}
    m = re.search(r'(?:l?episode)-0?(\d+)(?:-|$)', slug)
    if m:
        number = int(m.group(1))
        season = 2 if (post['date'] >= '2010-07-01' or 'saison-2' in slug) and 'saison-1' not in slug else 1
        if len(hits) == 1 and next(iter(hits))[1] == number:
            return next(iter(hits))
        return (season, number) if 1 <= number <= 20 else None
    return next(iter(hits)) if len(hits) == 1 else None


def post_pictures(post):
    """Full-size pictures of a post: gallery, linked originals, then inline images."""
    found = list(post['gallery'])
    for m in re.finditer(r'href="archive:([^"]+)"', post['body']):
        found.append(m.group(1))
    for m in re.finditer(r'src="archive:([^"]+)"', post['body']):
        found.append(m.group(1))
    if post['image']:
        found.insert(0, post['image'])
    out, seen = [], set()
    for f in found:
        base = re.sub(r'-\d+x\d+(\.\w+)$', r'\1', f)
        if os.path.isfile(os.path.join(ARCHIVE, base)):
            f = base
        if f not in seen and not re.search(r'-\d+x\d+\.\w+$', f):
            seen.add(f)
            out.append(f)
    return out


class Site:
    def __init__(self):
        self.post_slugs = set()
        self.link_map = {}

    def rewrite_link(self, href):
        p = local_path(href)
        if p is None:
            return href if re.match(r'^https?://', href or '') else None
        p = '/' + p.strip('/')
        if existing_image(p):
            return 'archive:' + p.lstrip('/')
        if p in self.link_map:
            return self.link_map[p]
        seg = p.strip('/').split('/')
        if len(seg) == 1 and seg[0] in self.post_slugs:
            return '/actualites/' + seg[0]
        if seg[0] == 'tag' and len(seg) > 1:
            return '/tags/' + seg[1]
        if seg[0] in ('episodes', 'galerie'):
            return '/episodes'
        if seg[0] in ('equipe', 'casting'):
            return '/wiki/personnages'
        if seg[0] == 'la-serie':
            return '/la-serie'
        if p == '/':
            return '/'
        return None


SITE = Site()


def clean_body(nodes, collect_gallery=None):
    """Sanitise a list of soup nodes into body HTML.

    Returns (html, lead_image, videos). Gallery images found in NextGEN
    blocks are appended to collect_gallery when given.
    """
    wrap = BeautifulSoup('<div id="root"></div>', 'lxml').select_one('#root')
    for n in nodes:
        wrap.append(n)
    videos = []
    for c in wrap.find_all(string=lambda s: isinstance(s, Comment)):
        c.extract()
    for g in wrap.select('.ngg-galleryoverview, .ngg-gallery-thumbnail-box, .piclenselink, .ngg-navigation, .ngg-clear'):
        if collect_gallery is not None:
            for a in g.select('a[href]'):
                img = existing_image(a['href'])
                if img and '/thumbs/' not in img and img not in collect_gallery:
                    collect_gallery.append(img)
        g.decompose()
    for t in wrap.find_all(['object', 'embed', 'iframe']):
        yid = youtube_id(str(t))
        parent_obj = t.find_parent('object')
        if parent_obj is not None:
            continue
        if yid and yid not in videos:
            videos.append(yid)
            marker = BeautifulSoup('<div class="video-embed" data-youtube="%s"></div>' % yid, 'lxml').div
            t.replace_with(marker)
        else:
            t.decompose()
    for t in wrap.select('script, style, noscript, form, map, input, .sociable, .breadcrumb, .postmeta, .date, .clear, .comments, .wp-caption-text'):
        t.decompose()
    lead = None
    for img in wrap.find_all('img'):
        p = existing_image(img.get('src'))
        if not p:
            img.decompose()
            continue
        if lead is None:
            lead = p
        alt = (img.get('alt') or '').strip()
        classes = img.get('class') or []
        align = next((a for a in ('right', 'left', 'center') if 'align' + a in classes), None)
        img.attrs = {'src': 'archive:' + p, 'alt': '' if re.match(r'^[\w.-]+$', alt) else alt, 'loading': 'lazy'}
        if align:
            img['class'] = 'align-' + align
    for a in wrap.find_all('a'):
        href = SITE.rewrite_link(a.get('href'))
        if href is None:
            a.unwrap()
        else:
            a.attrs = {'href': href}
    for t in wrap.find_all(True):
        if t is wrap:
            continue
        if t.name in ('h1',):
            t.name = 'h2'
        if t.name in ('span', 'font', 'center', 'small', 'big', 'address', 'cite', 'code', 'pre', 'tt', 'dl', 'dt', 'dd',
                      'tfoot', 'caption', 'col', 'colgroup', 'abbr', 'acronym', 'ins', 'strike', 's', 'label', 'fieldset',
                      'html', 'body', 'area', 'param', 'h5', 'h6', 'section', 'article', 'header', 'footer', 'nav'):
            t.unwrap()
            continue
        if t.name not in ALLOWED:
            t.unwrap()
            continue
        if t.name not in ('a', 'img') and not (t.name == 'div' and 'video-embed' in (t.get('class') or [])):
            t.attrs = {}
    # drop wrappers that became empty
    changed = True
    while changed:
        changed = False
        for t in wrap.find_all(['p', 'div', 'strong', 'em', 'a', 'h2', 'h3', 'h4', 'li', 'ul', 'ol', 'b', 'i', 'u', 'blockquote']):
            if t.name == 'div' and t.get('data-youtube'):
                continue
            if not t.find(['img', 'div', 'br', 'hr']) and not t.get_text(strip=True).replace('\xa0', ''):
                t.decompose()
                changed = True
    for t in wrap.find_all('div'):
        if not t.get('data-youtube'):
            t.unwrap()
    html = ''.join(str(c) for c in wrap.contents)
    html = re.sub(r'\[(media|playlist)[^\]]*\]', '', html)
    html = re.sub(r'<p>(\s|&nbsp;|\xa0|<br/?>)*</p>', '', html)
    html = re.sub(r'\n\s*\n+', '\n', html).strip()
    return html, lead, videos


def strip_lead_image(html, lead):
    """Remove the lead image from the top of the body when it opens the text."""
    if not lead:
        return html
    pat = r'^\s*<p>\s*(?:<a [^>]*>)?\s*<img [^>]*src="archive:%s"[^>]*/?>\s*(?:</a>)?\s*(?:<br/?>)?\s*</p>' % re.escape(lead)
    return re.sub(pat, '', html, count=1).strip()


def parse_date(text):
    m = re.search(r'([a-zéûôA-Z]+)\s+(\d{1,2}),\s*(\d{4})', text or '')
    if not m or m.group(1).lower() not in MONTHS:
        return None
    return '%04d-%02d-%02d' % (int(m.group(3)), MONTHS[m.group(1).lower()], int(m.group(2)))


def category_for(title, tags):
    t = (title + ' ' + ' '.join(tags)).lower()
    rules = [
        ('Sous-titres', r'sous-titres'),
        ('Audiences', r'audience'),
        ('DVD et Blu-ray', r'\bdvd\b|blu-ray|coffret'),
        ('Interviews', r'interview|s.exprime|confidences|parle de|reagit|réagit'),
        ('Spoilers', r'spoiler|r[ée]sum[ée]|synopsis'),
        ('Vidéos', r'trailer|sneak peek|webisode|kino|vid[ée]o|extrait|making.off'),
        ('Photos', r'photos?\b|images?\b|wallpaper|poster|galerie'),
        ('Diffusion', r'diffus|s[ée]rie club|nrj 12|syfy|sky1|ztele|w9'),
        ('Le site', r'\bsite\b|forum|facebook|panne|concours|ouverture'),
        ('Récompenses', r'award|nomin|emmy'),
    ]
    for name, pat in rules:
        if re.search(pat, t):
            return name
    return 'Actualités'


def soup_of(path):
    with open(path, encoding='utf-8', errors='replace') as f:
        return BeautifulSoup(f.read(), 'lxml')


def post_area(soup):
    return soup.select_one('.postarea') or soup.select_one('.postareawide')


def text_after_label(area, label):
    """Value following a <strong>Label :</strong> in an info paragraph."""
    for st in area.find_all('strong'):
        if st.get_text(' ', strip=True).rstrip(' :').lower().startswith(label.lower()):
            out = []
            for sib in st.next_siblings:
                if getattr(sib, 'name', None) in ('strong', 'br'):
                    break
                out.append(sib.get_text() if hasattr(sib, 'get_text') else str(sib))
            val = ''.join(out).strip(' :\xa0\n')
            return None if val in ('', '?') else val
    return None


def main():
    posts = []
    for name in sorted(os.listdir(ARCHIVE)):
        if name in SKIP_DIRS or name.startswith('wp-login') or name.startswith('.'):
            continue
        idx = os.path.join(ARCHIVE, name, 'index.html')
        if os.path.isfile(idx):
            SITE.post_slugs.add(name)
    # explicit targets for the old sections
    for d in os.listdir(os.path.join(ARCHIVE, 'equipe')):
        if os.path.isfile(os.path.join(ARCHIVE, 'equipe', d, 'index.html')):
            char = slugify(re.split(r'-(?=robert|justin|david|brian|jamil|alaina|ming|lou)', d)[0])
            SITE.link_map['/equipe/' + d] = '/wiki/personnages/' + char
            SITE.link_map['/casting/' + d] = '/wiki/personnages/' + char

    skipped = []
    for slug in sorted(SITE.post_slugs):
        soup = soup_of(os.path.join(ARCHIVE, slug, 'index.html'))
        area = post_area(soup)
        h1 = area.find('h1') if area else None
        date = parse_date(area.select_one('.date .time').get_text()) if area and area.select_one('.date .time') else None
        if not area or not h1 or not date:
            skipped.append(slug)
            continue
        title = h1.get_text(' ', strip=True)
        author_a = area.select_one('.dateleft a[href*="/author/"]')
        tags = [a.get_text(strip=True) for a in area.select('.postmeta .tags a')]
        nodes = []
        started = False
        for child in list(area.children):
            if getattr(child, 'name', None) == 'div' and 'date' in (child.get('class') or []):
                started = True
                continue
            if not started:
                continue
            if getattr(child, 'name', None) == 'div' and ('postmeta' in (child.get('class') or []) or 'comments' in (child.get('class') or [])):
                break
            nodes.append(child)
        gallery = []
        nodes = [n.extract() for n in nodes]
        html, lead, videos = clean_body(nodes, gallery)
        html = strip_lead_image(html, lead)
        posts.append({'slug': slug, 'title': title, 'date': date, 'author': author_a.get_text(strip=True) if author_a else None,
                      'tags': tags, 'category': category_for(title, tags), 'body': html, 'image': lead or (gallery[0] if gallery else None),
                      'gallery': gallery, 'videos': videos})
    posts.sort(key=lambda p: (p['date'], p['slug']))

    # Lead pictures the dump lost. The old front page (Wayback snapshots, see
    # scripts/fetch_wayback_extras.sh) tells which picture each post had; use the
    # full-size file when the dump has it, else the small thumbnail the Wayback
    # Machine kept.
    pack_dir = os.path.dirname(os.path.abspath(OUT))
    lead_file = os.path.join(pack_dir, 'lead_images.json')
    leads = json.load(open(lead_file, encoding='utf-8')) if os.path.isfile(lead_file) else {}
    recovered = 0
    for post in posts:
        if post['image'] or post['slug'] not in leads:
            continue
        path = leads[post['slug']].lstrip('/')
        small = re.sub(r'(\.\w+)$', r'-150x150\1', path)
        for candidate, root in ((path, ARCHIVE), ('wayback/files/' + path, pack_dir), ('wayback/thumbs/' + path + '.png', pack_dir), ('wayback/files/' + small, pack_dir)):
            if os.path.isfile(os.path.join(root, candidate)):
                post['image'] = candidate if root == ARCHIVE else 'pack:' + candidate
                recovered += 1
                break
    print('lead pictures recovered from the Wayback Machine', recovered)

    # La série page
    pages = []
    area = post_area(soup_of(os.path.join(ARCHIVE, 'la-serie', 'index.html')))
    nodes = [c.extract() for c in list(area.children)]
    html, lead, _ = clean_body(nodes)
    html = re.sub(r'^\s*(<h2>La série</h2>|<br/?>|\s)*', '', html)
    pages.append({'slug': 'la-serie', 'title': 'La série', 'body': html, 'image': None, 'gallery': []})

    # Characters and actors
    characters = []
    order = ['nicholas-rush-robert-carlyle', 'everett-young-justin-louis', 'eli-wallace-david-blue', 'matthew-scott-brian-j-smith',
             'ronald-greer-jamil-walker-smith', 'chloe-armstrong', 'tamara-johansen-alaina-huffman', 'camille-wray-ming-na',
             'colonel-telford-lou-diamond-phillips']
    home = soup_of(os.path.join(ARCHIVE, 'index.html'))
    nav_titles = {local_path(a.get('href')).strip('/'): a.get_text(strip=True) for a in home.find_all('a') if local_path(a.get('href') or '')}
    for d in order:
        p = os.path.join(ARCHIVE, 'equipe', d, 'index.html')
        if not os.path.isfile(p):
            continue
        area = post_area(soup_of(p))
        title = nav_titles.get('equipe/' + d) or area.find('h1').get_text(' ', strip=True)
        char, _, actor = [x.strip() for x in title.partition('/')]
        img = None
        for im in area.find_all('img'):
            img = existing_image(im.get('src'))
            if img:
                break
        sections = {'character': [], 'actor': []}
        current = None
        for child in list(area.children):
            if getattr(child, 'name', None) == 'h1':
                label = child.get_text(' ', strip=True).lower()
                current = 'character' if 'personnage' in label else 'actor' if 'acteur' in label or 'actrice' in label else None
                continue
            if current and getattr(child, 'name', None):
                sections[current].append(child)
        char_html = clean_body([n.extract() for n in sections['character']])[0]
        actor_html = clean_body([n.extract() for n in sections['actor']])[0]
        char_html = re.sub(r'</?strong>', '', char_html)
        actor_html = re.sub(r'</?strong>', '', actor_html)
        slug = SITE.link_map['/equipe/' + d].rsplit('/', 1)[1]
        prefix = CAST_GALLERY.get(slug)
        gdir = os.path.join(ARCHIVE, 'wp-content', 'gallery', 'casting-saison-1')
        shots = sorted('wp-content/gallery/casting-saison-1/' + f for f in os.listdir(gdir)
                       if prefix and f.startswith(prefix) and f.lower().endswith(IMG_EXT))
        # The casting portrait was never archived for two characters: use a promo shot.
        if not img and shots:
            img = shots[0]
        characters.append({'slug': slug, 'legacy': ['/equipe/' + d, '/casting/' + d],
                           'gallery': shots, 'actor_image': shots[-1] if shots else None,
                           'character': char, 'actor': actor,
                           'character_body': char_html, 'actor_body': actor_html, 'image': img})

    # Episodes: facts from the data file, text and pictures from the archive
    data = json.load(open(EPISODES_JSON, encoding='utf-8'))
    episodes = {(e['season'], e['number']): dict(e, synopsis='', guest_cast=None, image=None, promo=[], backstage=[]) for e in data['episodes']}
    for e in episodes.values():
        # The archive's own title list writes multi-part episodes as "Air 1/3".
        total = {'Air': 3}.get(e['title_fr'].split(' - Partie ')[0], 2)
        e['title_fr'] = re.sub(r' - Partie (\d)$', lambda m: ' %s/%d' % (m.group(1), total), e['title_fr'])
        e['title_original'] = re.sub(r' \(Part (\d)\)$', r', Part \1', e['title_original'])
    ep_dir = os.path.join(ARCHIVE, 'episodes')
    for d in sorted(os.listdir(ep_dir)):
        m = re.match(r'episode-0?(\d+)-', d)
        p = os.path.join(ep_dir, d, 'index.html')
        if not m or not os.path.isfile(p):
            continue
        ep = episodes.get((1, int(m.group(1))))
        ep.setdefault('legacy', []).append('/episodes/' + d)
        area = post_area(soup_of(p))
        ep['guest_cast'] = text_after_label(area, 'Casting secondaire')
        for im in area.find_all('img'):
            if existing_image(im.get('src')):
                ep['image'] = existing_image(im.get('src'))
                break
        syn = []
        h = next((x for x in area.find_all('h3') if 'synopsis' in x.get_text().lower()), None)
        if h:
            for sib in h.find_next_siblings():
                if sib.name == 'h3':
                    break
                syn.append(sib)
        ep['synopsis'] = clean_body([n.extract() for n in syn])[0]
        for a in area.select('.ngg-gallery-thumbnail a[href]'):
            img = existing_image(a['href'])
            target = ep['backstage'] if '/mo-' in (img or '') else ep['promo']
            if img and img not in target:
                target.append(img)
    gal = os.path.join(ARCHIVE, 'wp-content', 'gallery')
    other_galleries = []
    for d in sorted(os.listdir(gal)):
        files = sorted(f for f in os.listdir(os.path.join(gal, d)) if f.lower().endswith(IMG_EXT))
        rel = ['wp-content/gallery/%s/%s' % (d, f) for f in files]
        if not rel:
            continue
        m = re.match(r'(mo-)?(?:saison-1-)?episode-0?(\d+)', d)
        if m:
            ep = episodes[(1, int(m.group(2)))]
            target = ep['backstage'] if m.group(1) else ep['promo']
            for r in rel:
                if r not in target:
                    target.append(r)
        else:
            other_galleries.append((d, rel))
    titles = {'casting-saison-1': 'Photos du casting – Saison 1', 'wallpapers-saison-1': 'Fonds d\'écran – Saison 1', 'kit-de-presse': 'Kit de presse'}
    for d, rel in other_galleries:
        pages.append({'slug': 'galerie/' + d, 'title': titles.get(d, d.replace('-', ' ').capitalize()), 'body': '', 'image': rel[0], 'gallery': rel,
                      'legacy': {'casting-saison-1': ['/galerie/casting/saison-1', '/galerie/casting'], 'wallpapers-saison-1': ['/galerie/wallpapers-saison-1']}.get(d, [])})
    for sub, kind in (('saison-1', 'promo'), ('making-off', 'backstage')):
        base = os.path.join(ARCHIVE, 'galerie', sub)
        for d in sorted(os.listdir(base)):
            m = re.match(r'episode-0?(\d+)-', d)
            if m and os.path.isdir(os.path.join(base, d)):
                number = 7 if d == 'episode-17-earth' else int(m.group(1))
                episodes[(1, number)].setdefault('legacy', []).append('/galerie/%s/%s' % (sub, d))
    # News posts about one episode: their pictures and a link back.
    for post in posts:
        key = episode_of_post(post)
        if key not in episodes:
            continue
        ep = episodes[key]
        ep.setdefault('posts', []).append(post['slug'])
        if post['category'] in ('Photos', 'Spoilers', 'Vidéos'):
            target = ep['backstage'] if re.search(r'making|coulisses|envers|behind|effets-speciaux|tournage', slugify(post['slug'])) else ep['promo']
            for picture in post_pictures(post):
                if picture not in ep['promo'] and picture not in ep['backstage']:
                    target.append(picture)
    if EXTRA_JSON:
        for extra in json.load(open(EXTRA_JSON, encoding='utf-8'))['episodes']:
            ep = episodes.get((extra['season'], extra['number']))
            if not ep:
                continue
            ep['us_viewers_millions'] = extra.get('us_viewers_millions')
            if not ep.get('guest_cast') and extra.get('guest_cast'):
                ep['guest_cast'] = ', '.join('%s (%s)' % (g['actor'], g['character']) if g.get('character') else g['actor'] for g in extra['guest_cast']) + '.'
    for ep in episodes.values():
        ep.setdefault('posts', [])
        banner = existing_image('/wp-content/uploads/2009/10/s%de%02d.jpg' % (ep['season'], ep['number']))
        ep['image'] = ep['promo'][0] if ep['promo'] else banner
        if not ep['image']:
            by_slug = {post['slug']: post for post in posts}
            ep['image'] = next((by_slug[slug]['image'] for slug in ep['posts'] if by_slug[slug]['image']), None)

    tags = sorted({t for p in posts for t in p['tags']}, key=str.lower)
    # Links to a tag no post carries any more become a search for that word.
    tag_slugs = {slugify(t) for t in tags}
    def fix_tag_links(html):
        return re.sub(r'href="/tags/([^"/]+)"', lambda m: m.group(0) if m.group(1) in tag_slugs else 'href="/recherche?s=%s"' % m.group(1).replace('-', '+'), html or '')
    for item in posts + pages:
        item['body'] = fix_tag_links(item.get('body'))
    out = {
        'site': {'name': 'Stargate Universe', 'slogan': 'Le site francophone', 'theme': 'sgu'},
        'series': {'name': 'Stargate Universe', 'abbreviation': 'sgu', 'dates': '2009 – 2011',
                   'creators': 'Brad Wright, Robert C. Cooper', 'image': existing_image('/wp-content/uploads/2009/08/la_serie.jpg')},
        'seasons': data['seasons'],
        'episodes': [episodes[k] for k in sorted(episodes)],
        'characters': characters,
        'pages': pages,
        'articles': posts,
        'tags': tags,
        'episode_sources': data.get('sources', []),
    }
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(out, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    imgs = sum(1 for p in posts if p['image'])
    print('articles', len(posts), 'with image', imgs, 'galleries', sum(1 for p in posts if p['gallery']), 'videos', sum(len(p['videos']) for p in posts))
    print('skipped', skipped)
    print('characters', len(characters), 'pages', len(pages), 'episodes', len(episodes), 'tags', len(tags))
    print('episode pictures', sum(len(e['promo']) + len(e['backstage']) for e in episodes.values()))
    import collections
    print(collections.Counter(p['category'] for p in posts).most_common())


if __name__ == '__main__':
    main()
