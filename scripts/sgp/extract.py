#!/usr/bin/env python3
"""Builds content/sgp/content.json from the old stargate-pegasus.com site.

Usage: python3 scripts/sgp/extract.py <old site folder> <mysqldump .sql or .gz> [output.json]

The old site folder is the PHP site (with .htaccess, *.php, Templates/).
Pictures are not copied: the pack refers to them by their path in that folder
(Templates/Images/...), and the Drupal import reads them from there.
"""
import collections
import html as htmlmod
import json
import os
import re
import sys
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from bs4 import BeautifulSoup  # noqa: E402

import mysqldump  # noqa: E402
from oldsite import OldSite, bbcode, clean, link, text  # noqa: E402
from pages import facts_lines, french_date, minutes, split  # noqa: E402

SERIES = [
    # abbreviation, name, legacy id, template, colour of the series in the design.
    ('sg1', 'Stargate SG-1', 1, 'la-serie-sg1.tpl'),
    ('sga', 'Stargate Atlantis', 2, 'la-serie-sga.tpl'),
    ('sgu', 'Stargate Universe', 3, 'la-serie-sgu.tpl'),
]
EPISODE_PHP = {'sg1-episode.php': 'sg1', 'sga-episode.php': 'sga', 'sgu-episode.php': 'sgu'}
SEASON_PHP = {'sg1-saison.php': 'sg1', 'sga-saison.php': 'sga', 'sgu-saison.php': 'sgu'}
TV_FILMS = 99  # Season number of the "Téléfilms" group of a series.

WIKI_PHP = {
    'article-technologie-sga.php': 'Technologies',
    'article-personnage-sga.php': 'Personnages',
    'article-vaisseaux-sga.php': 'Vaisseaux',
    'article-peuples-sga.php': 'Peuples',
}
WIKI_TEMPLATES = {
    'Technologies': 'sga-technologie-article-',
    'Personnages': 'sga-personnage-article-',
    'Vaisseaux': 'sga-vaisseaux-article-',
    'Peuples': 'sga-peuples-article-',
}
WIKI_INDEX_PHP = {
    'sga_technologie.php': 'Technologies', 'sga_personnages.php': 'Personnages',
    'sga_vaisseaux.php': 'Vaisseaux', 'sga_peuples.php': 'Peuples',
    'technologie.php': 'Technologies', 'personnages.php': 'Personnages',
    'vaisseaux.php': 'Vaisseaux', 'peuples.php': 'Peuples',
}
SHIP_GROUPS = {'1': 'Vaisseaux anciens', '2': 'Vaisseaux wraiths', '3': 'Vaisseaux terriens', '4': 'Vaisseaux asurans', '5': 'Autres vaisseaux'}

# Product types: [parent, child].
PRODUCTS = 'Produits dérivés'
GAMES = 'Jeux vidéo'
PRODUCT_PHP = {
    'dvd.php': (PRODUCTS, 'DVD'),
    'bd.php': (PRODUCTS, 'Bandes dessinées'),
    'livres.php': (PRODUCTS, 'Livres'),
    'cdaudio.php': (PRODUCTS, 'CD audio'),
    'figurines.php': (PRODUCTS, 'Figurines'),
    'jeux_officiels.php': (GAMES, 'Jeux officiels'),
    'jeux_en_ligne.php': (GAMES, 'Jeux en ligne'),
    'mods_stargate.php': (GAMES, 'Mods Stargate'),
    'jeux_stargate_pegasus.php': (GAMES, 'Jeux du site'),
    'stargate_css_page.php': (GAMES, 'Autres jeux et serveurs'),
    'jeux_video_autres.php': (GAMES, 'Autres jeux et serveurs'),
    'stargate_minecraft.php': (GAMES, 'Autres jeux et serveurs'),
}
PRODUCT_TYPES = [
    (PRODUCTS, 'Coffrets, livres, bandes dessinées, musiques et objets de collection Stargate.', ['DVD', 'CD audio', 'Livres', 'Bandes dessinées', 'Magazines', 'Figurines', 'Jeux de société']),
    (GAMES, 'Jeux officiels, jeux en ligne, mods et serveurs de jeu Stargate.', ['Jeux officiels', 'Jeux en ligne', 'Jeux du site', 'Mods Stargate', 'Autres jeux et serveurs']),
]

NEWS_CATEGORIES = {
    '1': 'Stargate SG-1', '2': 'Stargate Atlantis', '7': 'Stargate Universe', '8': 'Acteurs', '9': 'Franchise',
    '10': 'Produits dérivés', '11': 'Jeux vidéo', '12': 'Salons/Conventions', '13': 'Partenaires',
    '15': 'Stargate Pegasus', '18': 'Divers',
}
CATEGORY_ORDER = ['Stargate SG-1', 'Stargate Atlantis', 'Stargate Universe', 'Franchise', 'Acteurs', 'Produits dérivés', 'Jeux vidéo', 'Salons/Conventions', 'Stargate Pegasus', 'Partenaires', 'Divers']


def slug(value):
    value = unicodedata.normalize('NFKD', value.replace('’', '').replace("'", '')).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', value.lower()).strip('-')[:96] or 'sans-titre'


def norm_name(name):
    return re.sub(r'[^a-z]', '', unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode().lower())


def names(value):
    value = text(value)
    if not value or value.lower() in ('inconnu', 'inconnue', 'indisponible'):
        return []
    parts = re.split(r'\s*,\s*|\s+et\s+|\s*&\s*|\s+and\s+', value)
    return [p.strip(' .') for p in parts if p.strip(' .')]


class Extractor:

    def __init__(self, root, dump):
        self.site = OldSite(root)
        self.db = mysqldump.read_dump(dump)
        self.routes = self.site.routes()
        self.pack = collections.OrderedDict()
        self.redirects = {}
        self.missing_pictures = set()

    def picture(self, path):
        path = link(path) if path else path
        if path and path.startswith('archive:'):
            path = path[len('archive:'):]
        if not path:
            return None
        if '://' in path or not self.site.exists(path):
            self.missing_pictures.add(path)
            return None
        return path

    def routes_of(self, php):
        return [r for r in self.routes if r['php'] == php and r.get('fic') and self.site.exists('Templates/Pages/' + r['fic'])]

    def linked_address(self, regex):
        """An address of the old site that some page links to (for pages no route lists)."""
        if not hasattr(self, '_hrefs'):
            self._hrefs = set()
            folder = os.path.join(self.site.root, 'Templates/Pages')
            for name in os.listdir(folder):
                self._hrefs.update(re.findall(r'href="([\w\-]+\.html)"', self.site.read('Templates/Pages/' + name)))
            for row in self.db['news']:
                self._hrefs.update(re.findall(r'(?:stargate-pegasus\.com/)?([\w\-]+\.html)', row['news_contenu'] or ''))
        for href in sorted(self._hrefs):
            if re.fullmatch(regex, href):
                return '/' + href
        return None

    def redirect(self, old, target):
        self.redirects[old] = target

    # ---- Series, seasons, episodes ---------------------------------------

    def series(self):
        out = []
        for weight, (abbr, name, legacy_id, template) in enumerate(SERIES):
            picture, facts, body = split(clean(self.site.page(template)))
            info = dict(facts)
            dates = text(info.get('Années de production', '')).replace('-', ' – ')
            # "Histoire" is the description; the main cast follows it.
            body = re.sub(r'<h2>Histoire</h2>\n?', '', body)
            body = body.replace('<h2>Cast principal</h2>', '<h2>Distribution principale</h2>')
            out.append({
                'name': name,
                'abbreviation': abbr,
                'dates': dates,
                'creators': text(info.get('Créateurs', '')),
                'image': self.picture(picture),
                'description': body,
                'facts': facts_lines(facts, skip=('créateurs', 'années de production')),
                'weight': weight,
                'legacy': ['/la-serie-%d.html' % legacy_id],
            })
            if any(r['php'] == 'liste_telefilms.php' and r['cond'].get('idSerie') == str(legacy_id) for r in self.routes if r.get('cond')):
                self.redirect('/liste-telefilms-%d.html' % legacy_id, 'season:%s:%d' % (abbr, TV_FILMS))
            self.redirect('/%s-acteurs-1.html' % abbr, 'internal:/acteurs?serie=%s' % abbr)
        self.pack['series'] = out

    def seasons_and_episodes(self):
        seasons = {}
        episodes = {}
        teasers = {}
        for php, abbr in SEASON_PHP.items():
            for route in self.routes_of(php):
                number = int(route['cond']['idSaison'])
                seasons[(abbr, number)] = {'serie': abbr, 'number': number, 'title': 'Saison %d' % number, 'legacy': [route['url']]}
                raw = self.site.page(route['fic'])
                soup = BeautifulSoup(raw, 'html.parser')
                for bloc in soup.select('div.bloc_episode'):
                    a = bloc.find('a', href=True)
                    m = re.search(r'saison-(\d+)-episode-(\d+)\.html', a['href']) if a else None
                    if not m:
                        continue
                    img = bloc.find('img')
                    paragraphs = bloc.find_all('p')
                    teaser = text(str(paragraphs[-1])) if len(paragraphs) > 1 else ''
                    teasers[(abbr, int(m.group(1)), int(m.group(2)))] = {
                        'thumb': self.picture(link(img['src'])) if img else None,
                        'teaser': teaser,
                    }
        for php, abbr in EPISODE_PHP.items():
            for route in self.routes_of(php):
                season, number = int(route['cond']['idSaison']), int(route['cond']['idEpisode'])
                episodes[(abbr, season, number)] = self.episode(route, abbr, season, number, teasers.get((abbr, season, number), {}))
        # TV films: a "Téléfilms" group in their series.
        films = {'1': ('sg1', 1), '2': ('sg1', 2), '3': ('sga', 1)}
        for route in self.routes_of('telefilms.php'):
            abbr, number = films[route['cond']['idTelefilm']]
            seasons.setdefault((abbr, TV_FILMS), {'serie': abbr, 'number': TV_FILMS, 'title': 'Téléfilms', 'legacy': []})
            episodes[(abbr, TV_FILMS, number)] = self.episode(route, abbr, TV_FILMS, number, {})
        # Seasons whose page never existed but have episodes (none expected).
        for (abbr, season, number) in episodes:
            seasons.setdefault((abbr, season), {'serie': abbr, 'number': season, 'title': 'Saison %d' % season, 'legacy': []})
        for key, data in seasons.items():
            dates = sorted(e['air_date'] for k, e in episodes.items() if k[:2] == key and e.get('air_date'))
            if dates:
                data['first_aired'], data['last_aired'] = dates[0], dates[-1]
            data['episode_count'] = sum(1 for k in episodes if k[:2] == key)
        self.pack['seasons'] = [seasons[k] for k in sorted(seasons, key=lambda k: ([s[0] for s in SERIES].index(k[0]), k[1]))]
        self.pack['episodes'] = [episodes[k] for k in sorted(episodes, key=lambda k: ([s[0] for s in SERIES].index(k[0]), k[1], k[2]))]

    def episode(self, route, abbr, season, number, extra):
        picture, facts, body = split(clean(self.site.page(route['fic'])))
        info = {label.lower(): value for label, value in facts}
        title = text(info.get('titre français', '')) or route.get('titrePage') or ''
        # "Une nouvelle ère 1/2" keeps its part number; titles in the old
        # menu used "Partie 1".
        sections = dict(re.findall(r'<h2>([^<]+)</h2>\n?<p>(.*?)</p>', body, re.S))
        synopsis = sections.get('Synopsis', '')
        guests = text(sections.get('Guest stars', '') or sections.get('Casting', ''))
        rest = re.sub(r'<h2>(Synopsis|Guest stars|Casting)</h2>\n?<p>.*?</p>\n?', '', body, flags=re.S).strip()
        audience = text(info.get('audiences sur sci-fi', ''))
        if audience and re.match(r'^[\d.,]+ ?millions?$', audience):
            audience = audience.replace('.', ',') + ' de téléspectateurs (Sci-Fi)'
        elif audience.lower() in ('indisponible', 'inconnue', 'inconnu'):
            audience = ''
        air = french_date(text(info.get('première diffusion', '')))
        extra_facts = facts_lines([(l, v) for l, v in facts if l.lower() in (
            'date de sortie (amérique du nord)', 'date de sortie (france)', 'budget')])
        premiere = text(info.get('première diffusion', ''))
        if premiere and '(' in premiere:
            extra_facts = (extra_facts + '\n' if extra_facts else '') + 'Première diffusion : ' + re.sub(r'^le\s+', '', premiere)
        data = {
            'serie': abbr,
            'season': season,
            'number': number,
            'title_fr': title.strip(),
            'title_original': text(info.get('titre original', '')),
            'air_date': air,
            'duration': minutes(text(info.get("durée de l'épisode", '') or info.get('durée du film', ''))),
            'audience': audience or None,
            'directors': names(info.get('réalisateur', '') or info.get('réalisateurs', '')),
            'writers': names(info.get('scénaristes', '') or info.get('scénariste', '')),
            'guest_cast': guests or None,
            'synopsis': ('<p>%s</p>' % synopsis.strip()) if synopsis.strip() else '',
            'body': rest,
            'facts': extra_facts,
            'image': self.picture(picture),
            'thumb': extra.get('thumb'),
            'teaser': extra.get('teaser'),
            'legacy': [route['url']],
        }
        return data

    # ---- People ----------------------------------------------------------

    def people(self):
        people = collections.OrderedDict()
        for abbr in ('sg1', 'sga', 'sgu'):
            for route in self.routes_of('%s-acteurs-article.php' % abbr):
                self.person(people, route['fic'], route.get('titrePage'), abbr, route['url'])
        # Pages no menu linked to.
        used = {r.get('fic') for r in self.routes}
        for abbr in ('sg1', 'sga', 'sgu'):
            for name in sorted(os.listdir(os.path.join(self.site.root, 'Templates/Pages'))):
                if name.startswith('%s-acteurs-article-' % abbr) and name not in used:
                    self.person(people, name, None, abbr, '/' + name.replace('.tpl', '.html'))
        self.pack['people'] = list(people.values())

    def person(self, people, template, title, abbr, url):
        raw = self.site.page(template)
        picture, facts, body = split(clean(raw))
        if not title:
            m = re.search(r'<img [^>]*alt="([^"]+)"', raw)
            title = m.group(1) if m else None
        if not title:
            return
        title = title.strip()
        body = self.filmography(body)
        key = norm_name(title)
        person = people.get(key)
        if not person:
            person = people[key] = {'slug': slug(title), 'name': title, 'jobs': ['Acteur'], 'series': [], 'legacy': [], 'image': None, 'body': '', 'facts': ''}
        if abbr not in person['series']:
            person['series'].append(abbr)
        if url:
            person['legacy'].append(url)
        # The longest version of the biography wins.
        if len(text(body)) > len(text(person['body'])):
            person['body'] = body
            person['facts'] = facts_lines(facts)
            person['image'] = self.picture(picture) or person['image']
        elif not person['image']:
            person['image'] = self.picture(picture)

    @staticmethod
    def filmography(body):
        def convert(m):
            lines = [l.strip() for l in re.split(r'<br>|\n', m.group(1)) if l.strip()]
            items = []
            for line in lines:
                lm = re.match(r'^(\d{4}(?:\s*[–-]\s*\d{4})?)\s*:\s*(.+)$', text(line))
                if lm:
                    items.append('<li><strong>%s</strong> %s</li>' % (lm.group(1).replace(' ', ''), htmlmod.escape(lm.group(2), quote=False)))
                else:
                    items.append('<li>%s</li>' % line)
            return '<h2>Filmographie</h2>\n<ul class="filmography">%s</ul>' % ''.join(items)
        body = re.sub(r'<h2>Filmographie</h2>\n?<p>(.*?)</p>', convert, body, flags=re.S)
        # Some lists run over several paragraphs.
        body = re.sub(r'</ul>\n<p>((?:\d{4}\s*:[^<]*(?:<br>)?)+)</p>', lambda m: convert(m).split('<ul class="filmography">', 1)[1], body)
        body = body.replace('</ul></ul>', '</ul>')
        return body

    # ---- Encyclopedia ----------------------------------------------------

    def wiki(self):
        entries = collections.OrderedDict()
        used = {r.get('fic') for r in self.routes}
        for php, category in WIKI_PHP.items():
            for route in self.routes_of(php):
                group = None
                if category == 'Vaisseaux':
                    group = SHIP_GROUPS.get(route['cond'].get('idVaisseauxSGA'))
                self.wiki_entry(entries, route['fic'], route.get('titrePage'), category, route['url'], group)
        for category, prefix in WIKI_TEMPLATES.items():
            for name in sorted(os.listdir(os.path.join(self.site.root, 'Templates/Pages'))):
                if name.startswith(prefix) and name not in used:
                    self.wiki_entry(entries, name, None, category, None, None)
        # Dossiers.
        for route in self.routes_of('dossier.php'):
            if route['fic'] == 'dossier.tpl':
                self.redirect(route['url'], 'category:Dossiers')
                continue
            self.wiki_entry(entries, route['fic'], route.get('titrePage'), 'Dossiers', route['url'], None)
        # Index pages of the old encyclopedia: letters and groups.
        for route in self.routes:
            if route['php'] in WIKI_INDEX_PHP:
                # "Lettre B" pages of the old index keep their letter.
                letter = re.search(r'-([a-z])\.tpl$', route.get('fic') or '')
                self.redirect(route['url'], 'category:' + WIKI_INDEX_PHP[route['php']] + ('?lettre=' + letter.group(1) if letter else ''))
        for old in ('/article-1.html', '/article-2.html', '/article-3.html'):
            self.redirect(old, 'category:Technologies')
        self.pack['wiki'] = list(entries.values())
        self.pack['wiki_categories'] = [
            {'name': 'Personnages', 'description': 'Les habitants de la galaxie de Pégase et les membres de l’expédition Atlantis.'},
            {'name': 'Peuples', 'description': 'Les peuples et les espèces rencontrés dans la galaxie de Pégase.'},
            {'name': 'Technologies', 'description': 'Armes, appareils et inventions des Anciens, des Wraiths, des Terriens et des autres peuples.'},
            {'name': 'Vaisseaux', 'description': 'Les vaisseaux de Stargate Atlantis, des jumpers aux vaisseaux ruches.'},
            {'name': 'Dossiers', 'description': 'Articles et calculs autour de l’univers Stargate.'},
        ]

    def wiki_entry(self, entries, template, title, category, url, group):
        raw = self.site.page(template)
        picture, facts, body = split(clean(raw))
        if not title:
            m = re.search(r'<img [^>]*alt="([^"]+)"', raw)
            title = m.group(1) if m else None
        if not title:
            return
        appearance = []
        kept = []
        for label, value in facts:
            if label.lower() == 'apparition':
                for href in re.findall(r'href="legacy:/[^"]*?saison-(\d+)-episode-(\d+)\.html"', value):
                    appearance.append({'serie': 'sga', 'season': int(href[0]), 'number': int(href[1])})
                if not appearance:
                    kept.append((label, value))
            else:
                kept.append((label, value))
        if not url:
            number = re.search(r'-(\d+)\.tpl$', template).group(1)
            family = re.sub(r'-article-\d+\.tpl$', '', template)
            url = self.linked_address(r'%s-\d+-article-%s\.html' % (re.escape(family), number))
        key = (category, slug(title))
        entries[key] = {
            'slug': slug(title),
            'title': title.strip(),
            'category': category,
            'serie': 'sga',
            'image': self.picture(picture),
            'facts': facts_lines(kept),
            'appearance': appearance,
            'body': body,
            'tags': [group] if group else [],
            'legacy': [url] if url else [],
        }

    # ---- Products --------------------------------------------------------

    def products(self):
        out = collections.OrderedDict()
        index_targets = {}
        for php, (parent, child) in PRODUCT_PHP.items():
            for route in [r for r in self.routes if r['php'] == php]:
                fic = route.get('fic')
                if not fic or not self.site.exists('Templates/Pages/' + fic):
                    continue
                # Listing pages of the old site: dvd-sg1.tpl, bd.tpl, mods-stargate.tpl…
                is_index = fic in ('dvd-sg1.tpl', 'dvd-sga.tpl', 'bd-sg1.tpl', 'bd-sga.tpl', 'livres-sg1.tpl', 'livres-sga.tpl',
                                   'cdaudio.tpl', 'figurine-sga.tpl', 'jeux-officiels-stargate.tpl', 'jeux-en-ligne.tpl',
                                   'mods-stargate.tpl', 'jeux-stargate-pegasus.tpl', 'autres-jeux-video.tpl')
                if is_index:
                    serie = {'1': 'sg1', '2': 'sga'}.get(route['cond'].get('idSerieDVD') or route['cond'].get('idSerieBD') or route['cond'].get('idSerieLivres') or '')
                    target = 'product_type:' + child + (('?serie=' + serie) if serie and php != 'figurines.php' else '')
                    self.redirect(route['url'], target)
                    continue
                self.product(out, fic, route.get('titrePage'), parent, child, route['url'])
        # Pages with one product.
        self.product(out, 'magazines.tpl', 'Stargate Magazine', PRODUCTS, 'Magazines', '/magazine.html')
        self.product(out, 'stargate-minecraft.tpl', 'Minecraft', GAMES, 'Autres jeux et serveurs', '/stargate-minecraft.html')
        self.product(out, 'jeudesociete.tpl', 'La vengeance d’Apophis', PRODUCTS, 'Jeux de société', '/jeu-de-societe.html')
        for old in ('/dvd-0-1.html', '/livres-0-1.html', '/bd-0-1.html'):
            self.redirect(old, 'product_type:' + {'dvd': 'DVD', 'livres': 'Livres', 'bd': 'Bandes dessinées'}[old[1:].split('-')[0]])
        self.pack['product_types'] = [{'name': p, 'description': d, 'children': c} for p, d, c in PRODUCT_TYPES]
        self.pack['products'] = list(out.values())

    def product(self, out, template, title, parent, child, url):
        raw = self.site.page(template)
        picture, facts, body = split(clean(raw))
        info = {l.lower(): v for l, v in facts}
        title = (title or text(info.get('nom du jeu', '')) or '').strip()
        # Product names repeated as the first heading.
        body = re.sub(r'^<h2>[^<]*</h2>\n?(?=<h2>)', '', body)
        series = []
        haystack = title + ' ' + template
        if re.search(r'SG-?1|sg1', haystack):
            series.append('sg1')
        if re.search(r'Atlantis|sga|Weir|Wraith', haystack):
            series.append('sga')
        if re.search(r'Universe|sgu', haystack):
            series.append('sgu')
        key = slug(title)
        if key in out:
            key = slug(title + ' ' + template.replace('.tpl', ''))
        out[key] = {
            'slug': key,
            'title': title,
            'type': child,
            'type_parent': parent,
            'series': series,
            'image': self.picture(picture),
            'facts': facts_lines([(l, v) for l, v in facts if l.lower() not in ('description',)]),
            'body': body,
            'legacy': [url] if url else [],
        }

    # ---- News and events -------------------------------------------------

    def articles(self):
        out = []
        for row in self.db['news']:
            if row['news_validee'] != '1':
                continue
            title = mysqldump.stripslashes(row['news_nom']).strip()
            body = bbcode(mysqldump.stripslashes(row['news_contenu']))
            summary = text(mysqldump.stripslashes(row['news_descr']))
            nid = row['news_id']
            out.append({
                'slug': slug(title),
                'title': title,
                'date': int(row['news_date']),
                'category': NEWS_CATEGORIES.get(row['news_cat'], 'Divers'),
                'summary': summary,
                'body': body,
                'image': self.picture(row['news_image']),
                'author': self.member(row['news_idAut']),
                'legacy': ['/news-501-%s.html' % nid],
                'views': int(row['news_nbr_vu'] or 0),
            })
        for row in self.db['evenements']:
            title = mysqldump.stripslashes(row['evenements_nom']).strip()
            body = bbcode(mysqldump.stripslashes(row['evenements_contenu']))
            start, end = int(row['evenements_dateDeb']), int(row['evenements_dateFin'])
            when = self.period(start, end)
            place = mysqldump.stripslashes(row['evenements_lieu']).strip()
            intro = '<p><strong>Quand :</strong> %s<br><strong>Où :</strong> %s</p>' % (when, htmlmod.escape(place, quote=False))
            out.append({
                'slug': slug(title),
                'title': title,
                'date': int(row['evenements_datecreation']),
                'category': 'Salons/Conventions',
                'summary': text(mysqldump.stripslashes(row['evenements_descr'])),
                'body': intro + '\n' + body,
                'image': self.picture(row['evenements_image']),
                'author': self.member(row['evenements_idcreateur']),
                'legacy': ['/evenements-501-%s.html' % row['evenements_id']],
            })
        out.sort(key=lambda a: a['date'])
        # Unique slugs.
        seen = collections.Counter()
        for article in out:
            seen[article['slug']] += 1
            if seen[article['slug']] > 1:
                article['slug'] += '-%d' % seen[article['slug']]
        self.pack['article_categories'] = [{'name': name, 'description': self.category_description(name)} for name in CATEGORY_ORDER]
        self.pack['articles'] = out

    def category_description(self, name):
        for row in self.db['categories_news']:
            if row['catNews_nom'] == name:
                return text(bbcode(mysqldump.stripslashes(row['catNews_desc'])))
        return ''

    @staticmethod
    def period(start, end):
        import datetime
        tz = datetime.timezone(datetime.timedelta(hours=1))
        a, b = datetime.datetime.fromtimestamp(start, tz), datetime.datetime.fromtimestamp(end, tz)
        months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']
        fmt = lambda d: '%s %s %d' % ('1er' if d.day == 1 else d.day, months[d.month - 1], d.year)
        if a.date() == b.date():
            return 'le ' + fmt(a)
        return 'du %s au %s' % (fmt(a), fmt(b))

    def member(self, member_id):
        for row in self.db.get('members', []):
            if row.get('mbs_id') == member_id:
                return mysqldump.stripslashes(row.get('mbs_pseudo') or '') or None
        for row in self.db.get('mbs_infos', []):
            if row.get('mbsi_id') == member_id:
                return mysqldump.stripslashes(row.get('mbsi_pseudo') or '') or None
        return None

    # ---- Pages, partners, menus -----------------------------------------

    def pages(self):
        out = []

        def page(slug_, title, template, legacy, footer=False, fix=None):
            raw = self.site.page(template)
            if fix:
                raw = fix(raw)
            out.append({'slug': slug_, 'title': title, 'body': clean(raw), 'legacy': legacy, 'footer': footer})

        def donate(raw):
            m = re.search(r'name="hosted_button_id" value="(\w+)"', raw)
            button = '<p class="centrer"><a href="https://www.paypal.com/donate/?hosted_button_id=%s">Faire un don avec PayPal</a></p>' % m.group(1) if m else ''
            return re.sub(r'<form.*?</form>', button, raw, flags=re.S)

        def banners(raw):
            return re.sub(r'<textarea[^>]*>(.*?)</textarea>', lambda m: '<code>%s</code>' % m.group(1).strip().replace('\n', ' '), raw, flags=re.S)

        def faq(raw):
            return re.sub(r'<img [^>]*FAQimg[^>]*>\s*', '', raw)

        page('faq', 'Questions fréquentes', 'faq.tpl', ['/faq.html'], True, faq)
        page('charte', 'Charte du site', 'charte.tpl', ['/charte.html'], True)
        page('nous-soutenir', 'Nous soutenir', 'nous-soutenir.tpl', ['/nous-soutenir.html'], True, lambda r: faq(donate(r)))
        page('nos-bannieres', 'Nos bannières', 'nos-bannieres.tpl', ['/bannieres-stargate-pegasus.html'], True, banners)
        page('fanfics', 'Fanfics', 'fanfics.tpl', ['/fanfics.html'], False)
        for item in out:
            item['body'] = item['body'].replace('<code>', '<p><code>').replace('</code>', '</code></p>') if item['slug'] == 'nos-bannieres' else item['body']
        self.pack['pages'] = out

    def partners(self):
        rows = [r for r in self.db['partenaires'] if r['accepte_partenaires'] == '1']
        rows.sort(key=lambda r: int(r['date_validation'] or 0))
        self.pack['partners'] = [{
            'name': mysqldump.stripslashes(r['nom_partenaires']).strip(),
            'url': r['lien_partenaires'].strip(),
            'description': mysqldump.stripslashes(r['description_partenaires']).strip(),
        } for r in rows]

    def fixed_redirects(self):
        fixed = {
            '/index.html': 'internal:/',
            '/accueil.html': 'internal:/',
            '/news-500.html': 'internal:/actualites',
            '/acteurs.html': 'internal:/acteurs',
            '/sg1-acteurs-1.html': 'internal:/acteurs?serie=sg1',
            '/sga-acteurs-1.html': 'internal:/acteurs?serie=sga',
            '/sgu-acteurs-1.html': 'internal:/acteurs?serie=sgu',
            '/contact.html': 'internal:/contact',
            '/partenaires.html': 'route:tvshow_core.partners',
            '/demande-partenariat.html': 'internal:/contact',
            '/evenements-500.html': 'news_category:Salons/Conventions',
            '/cd-audio-1.html': 'product_type:CD audio',
            '/figurines-2-1.html': 'product_type:Figurines',
            '/rss.xml': 'internal:/actualites/rss.xml',
            '/sgp_flux.xml': 'internal:/actualites/rss.xml',
            '/equipe.html': 'internal:/contact',
            # Pages the old menus linked to but that were never written.
            '/sg1-saison-4.html': 'serie:sg1',
            '/sgu-saison-2.html': 'serie:sgu',
        }
        # Old listing: 8 news a page; new one: 12.
        for page in range(1, 15):
            target = (page - 1) * 8 // 12
            self.redirect('/news-500-page-%d.html' % page, 'internal:/actualites' + ('?page=%d' % target if target else ''))
        for old, target in fixed.items():
            if target:
                self.redirect(old, target)
        self.pack['legacy_patterns'] = [
            # Any news address (with or without its title, comments pages
            # included) goes to the article.
            {'from': r'^/news-(?:501|601|602|603|604)-(\d+)(?:-.*)?\.html$', 'to': '/news-501-$1.html', 'lookup': True},
            {'from': r'^/evenements-(?:501|502)-(\d+)(?:-.*)?\.html$', 'to': '/evenements-501-$1.html', 'lookup': True},
            # Old listing pages.
            {'from': r'^/stargate-sg1-saison-4-episode-\d+\.html$', 'to': '/sg1', 'lookup': False},
            {'from': r'^/stargate-universe-saison-2-episode-\d+\.html$', 'to': '/sgu', 'lookup': False},
            {'from': r'^/evenements-500-page-\d+\.html$', 'to': '/actualites/rubrique/salons-conventions', 'lookup': False},
        ]

    def menus(self):
        """Main menu, three levels: section > column > links (see the design)."""
        S = lambda abbr: 'serie:' + abbr
        season = lambda abbr, n: 'season:%s:%d' % (abbr, n)
        seasons = lambda abbr: [{'title': s['title'], 'ref': season(abbr, s['number'])} for s in self.pack['seasons'] if s['serie'] == abbr and s['number'] != TV_FILMS]
        has_films = lambda abbr: any(s['serie'] == abbr and s['number'] == TV_FILMS for s in self.pack['seasons'])
        shop = lambda child, abbr: {'title': child, 'ref': 'product_type:%s' % child, 'query': 'serie=' + abbr}

        def serie(abbr, label, intro, all_label, extra):
            serie_links = [{'title': 'Présentation', 'ref': S(abbr)}]
            if has_films(abbr):
                serie_links.append({'title': 'Téléfilms', 'ref': season(abbr, TV_FILMS)})
            serie_links.append({'title': 'Acteurs', 'ref': 'internal:/acteurs?serie=' + abbr})
            columns = [{'title': 'La série', 'ref': S(abbr), 'children': serie_links}]
            eps = seasons(abbr)
            if len(eps) > 5:
                columns.append({'title': 'Épisodes', 'ref': S(abbr), 'children': eps[:5]})
                columns.append({'title': 'Épisodes (suite)', 'ref': S(abbr), 'children': eps[5:]})
            else:
                columns.append({'title': 'Épisodes', 'ref': S(abbr), 'children': eps})
            columns += extra
            return {'title': label, 'ref': S(abbr), 'description': intro + ' | ' + all_label, 'children': columns}

        products_of = lambda abbr: sorted({p['type'] for p in self.pack['products'] if abbr in p['series'] and p['type_parent'] == PRODUCTS}, key=lambda t: PRODUCT_TYPES[0][2].index(t))
        main = [
            {'title': 'Accueil', 'ref': 'internal:/'},
            {'title': 'Actualités', 'ref': 'internal:/actualites', 'description': 'Toutes les nouvelles de la franchise, par rubrique. | Toutes les actualités', 'children': [
                {'title': 'Par série', 'ref': 'internal:/actualites', 'children': [{'title': n, 'ref': 'news_category:' + n} for n in ('Stargate SG-1', 'Stargate Atlantis', 'Stargate Universe', 'Franchise')]},
                {'title': 'Par sujet', 'ref': 'internal:/actualites', 'children': [{'title': n, 'ref': 'news_category:' + n} for n in ('Acteurs', 'Produits dérivés', 'Jeux vidéo', 'Salons/Conventions')]},
                {'title': 'Le site', 'ref': 'news_category:Stargate Pegasus', 'children': [{'title': n, 'ref': 'news_category:' + n} for n in ('Stargate Pegasus', 'Partenaires', 'Divers')]},
            ]},
            serie('sg1', 'SG-1', 'La série, ses téléfilms, ses acteurs et le guide des épisodes.', 'Tout sur Stargate SG-1',
                  [{'title': 'Produits dérivés', 'ref': 'product_type:' + PRODUCTS, 'children': [shop(t, 'sg1') for t in products_of('sg1')]}]),
            serie('sga', 'Atlantis', 'La série, ses acteurs, les épisodes et l’encyclopédie de Pégase.', 'Tout sur Stargate Atlantis', [
                {'title': 'Encyclopédie', 'ref': 'route:tvshow_core.wiki', 'children': [{'title': c, 'ref': 'category:' + c} for c in ('Personnages', 'Peuples', 'Technologies', 'Vaisseaux')]},
                {'title': 'Produits dérivés', 'ref': 'product_type:' + PRODUCTS, 'children': [shop(t, 'sga') for t in products_of('sga')]},
            ]),
            serie('sgu', 'Universe', 'La série, ses acteurs et le guide des épisodes.', 'Tout sur Stargate Universe', []),
            {'title': 'Encyclopédie', 'ref': 'route:tvshow_core.wiki', 'description': 'Personnages, peuples, technologies et vaisseaux. | Toute l’encyclopédie', 'children': [
                {'title': 'Univers', 'ref': 'route:tvshow_core.wiki', 'children': [{'title': c, 'ref': 'category:' + c} for c in ('Personnages', 'Peuples', 'Technologies', 'Vaisseaux')]},
                {'title': 'Acteurs', 'ref': 'internal:/acteurs', 'children': [{'title': n, 'ref': 'internal:/acteurs?serie=' + a} for a, n, _, _ in SERIES]},
                {'title': 'Dossiers', 'ref': 'category:Dossiers', 'children': [{'title': 'Dossiers et articles', 'ref': 'category:Dossiers'}]},
            ]},
            {'title': PRODUCTS, 'ref': 'product_type:' + PRODUCTS, 'description': 'DVD, livres, bandes dessinées et objets de collection. | Tous les produits dérivés', 'children': [
                {'title': 'Vidéo et audio', 'ref': 'product_type:' + PRODUCTS, 'children': [{'title': t, 'ref': 'product_type:' + t} for t in ('DVD', 'CD audio')]},
                {'title': 'Lecture', 'ref': 'product_type:' + PRODUCTS, 'children': [{'title': t, 'ref': 'product_type:' + t} for t in ('Livres', 'Bandes dessinées', 'Magazines')] + [{'title': 'Fanfics', 'ref': 'page:fanfics'}]},
                {'title': 'Objets', 'ref': 'product_type:' + PRODUCTS, 'children': [{'title': t, 'ref': 'product_type:' + t} for t in ('Figurines', 'Jeux de société')]},
            ]},
            {'title': GAMES, 'ref': 'product_type:' + GAMES, 'description': 'Jeux officiels, jeux en ligne et mods Stargate. | Tous les jeux vidéo', 'children': [
                {'title': 'Jeux', 'ref': 'product_type:' + GAMES, 'children': [{'title': t, 'ref': 'product_type:' + t} for t in ('Jeux officiels', 'Jeux en ligne', 'Jeux du site')]},
                {'title': 'Communauté', 'ref': 'product_type:' + GAMES, 'children': [{'title': 'Mods Stargate', 'ref': 'product_type:Mods Stargate'}, {'title': 'Autres et serveurs', 'ref': 'product_type:Autres jeux et serveurs'}]},
            ]},
        ]
        footer = [
            {'title': 'Questions fréquentes', 'ref': 'page:faq'},
            {'title': 'Charte', 'ref': 'page:charte'},
            {'title': 'Nous soutenir', 'ref': 'page:nous-soutenir'},
            {'title': 'Nos bannières', 'ref': 'page:nos-bannieres'},
            {'title': 'Partenaires', 'ref': 'route:tvshow_core.partners'},
            {'title': 'Contact', 'ref': 'internal:/contact'},
            {'title': 'Flux RSS', 'ref': 'internal:/actualites/rss.xml'},
        ]
        self.pack['menus'] = {'main': main, 'footer': footer}

    def polish(self):
        """Markup the new design styles: history cards, filmography tables, cast lists."""
        abbr_of_url = {'sg1': 'stargate-sg1', 'sga': 'stargate-atlantis', 'sgu': 'stargate-universe'}

        def history(body, serie):
            m = re.search(r'<h2>Historique</h2>\n?', body)
            if not m:
                return body
            head, rest = body[:m.end()], body[m.end():]
            nxt = re.search(r'\n?<h2>', rest)
            section, tail = (rest[:nxt.start()], rest[nxt.start():]) if nxt else (rest, '')
            parts = re.split(r'(?=<h3>)', section)
            out = []
            for part in parts:
                if not part.strip():
                    continue
                if part.startswith('<h3>'):
                    def heading(hm):
                        label = hm.group(1)
                        em = re.match(r'^(\d{1,2})\.(\d{2})\s', text(label))
                        if em:
                            href = 'legacy:/%s-saison-%d-episode-%d.html' % (abbr_of_url[serie], int(em.group(1)), int(em.group(2)))
                            return '<h3><a href="%s">%s</a></h3>' % (href, label)
                        return hm.group(0)
                    part = re.sub(r'^<h3>(.*?)</h3>', heading, part, count=1)
                    out.append('<div class="history-entry">\n%s\n</div>' % part.strip())
                else:
                    out.append(part.strip())
            return head + '\n'.join(out) + tail

        for entry in self.pack['wiki']:
            entry['body'] = history(entry['body'], entry['serie'])

        def films(body):
            def table(m):
                rows = []
                for item in re.findall(r'<li>(.*?)</li>', m.group(1), re.S):
                    ym = re.match(r'^<strong>(.*?)</strong>\s*(.*)$', item, re.S)
                    year, rest = (ym.group(1), ym.group(2)) if ym else ('', item)
                    rm = re.match(r'^(.*?),?\s*rôles?\s*:\s*(.*)$', rest, re.S | re.I)
                    title, role = (rm.group(1).strip(' ,'), rm.group(2).strip()) if rm else (rest.strip(), '')
                    rows.append('<tr><td>%s</td><td>%s</td><td>%s</td></tr>' % (year.replace('–', ' – '), title, role))
                return '<table class="filmography"><thead><tr><th>Année</th><th>Titre</th><th>Rôle</th></tr></thead><tbody>%s</tbody></table>' % ''.join(rows)
            return re.sub(r'<ul class="filmography">(.*?)</ul>', table, body, flags=re.S)

        for person in self.pack['people']:
            person['body'] = films(person['body'])

        people = {norm_name(p['name']): p for p in self.pack['people']}
        for serie in self.pack['series']:
            def cast(m):
                items = []
                for part in re.split(r',\s*(?![^()]*\))', text(m.group(1)).rstrip('. ')):
                    pm = re.match(r'^(.*?)\s*\((.*)\)$', part.strip())
                    actor, role = (pm.group(1), pm.group(2)) if pm else (part.strip(), '')
                    person = people.get(norm_name(actor))
                    legacy = None
                    if person:
                        own = [u for u in person['legacy'] if u.startswith('/%s-acteurs' % serie['abbreviation'])]
                        legacy = (own or person['legacy'] or [None])[0]
                    name = '<a href="legacy:%s">%s</a>' % (legacy, htmlmod.escape(actor)) if legacy else htmlmod.escape(actor)
                    items.append('<li>%s <span>%s</span></li>' % (name, htmlmod.escape(role)))
                return '<h2>Distribution principale</h2>\n<ul class="cast-list">%s</ul>' % ''.join(items)
            serie['description'] = re.sub(r'<h2>Distribution principale</h2>\n?<p>(.*?)</p>', cast, serie['description'], flags=re.S)

    def run(self):
        self.pack['site'] = {
            'name': 'Stargate Pegasus',
            'slogan': 'Actualités, épisodes et encyclopédie, en français',
            'theme': 'sgp',
            'wiki_path': 'encyclopedie',
            'wiki_label': 'Encyclopédie',
            'footer_text': 'Stargate Pegasus — site de fans, non officiel. Les séries, personnages et images appartiennent à leurs ayants droit respectifs.',
            'description': 'Stargate Pegasus, le site francophone sur Stargate SG-1, Atlantis et Universe : actualités, guide des épisodes, encyclopédie, acteurs et produits dérivés.',
            'social': {'facebook': 'https://www.facebook.com/pages/Stargate-Pegasus/177797575614702', 'x': 'https://twitter.com/PegasusStargate'},
        }
        self.series()
        self.seasons_and_episodes()
        self.people()
        self.wiki()
        self.products()
        self.articles()
        self.pages()
        self.partners()
        self.fixed_redirects()
        self.menus()
        self.polish()
        self.pack['aliases'] = [
            {'ref': 'route:tvshow_core.wiki', 'alias': '/encyclopedie'},
        ]
        self.pack['redirects'] = self.redirects
        return self.pack


def main():
    root, dump = sys.argv[1], sys.argv[2]
    output = sys.argv[3] if len(sys.argv) > 3 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../content/sgp/content.json')
    extractor = Extractor(root, dump)
    pack = extractor.run()
    os.makedirs(os.path.dirname(os.path.abspath(output)), exist_ok=True)
    with open(output, 'w', encoding='utf-8') as handle:
        json.dump(pack, handle, ensure_ascii=False, indent=1)
    for key, value in pack.items():
        if isinstance(value, list):
            print('%-20s %d' % (key, len(value)))
    print('%-20s %d' % ('redirects', len(pack['redirects'])))
    if extractor.missing_pictures:
        print('Pictures not found: %d' % len(extractor.missing_pictures))
        for path in sorted(extractor.missing_pictures)[:20]:
            print('  ' + path)


if __name__ == '__main__':
    main()
