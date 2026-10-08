# TV show sites — Drupal 11

One codebase for a line-up of TV show fan sites. The first site is
**stargateuniverse.fr**; stargate-origins.com, the-flash-france.fr,
green-arrow-france.fr and stargate-pegasus.com follow on the same base.

It is a rebuild from scratch of the Drupal 9 `tvshowsite` project. The content
model (news, wiki, episodes, people, series, seasons, partners) is the same;
the stack is new: Drupal 11, the usual contributed modules, no CSS framework.

## Contributed modules

Installed by Composer and enabled and configured at the end of the profile
install (`ContribSetup`):

| Module | Used for |
| --- | --- |
| Pathauto + Token | URL patterns (`/actualites/[title]`, `/sgu/saison-1/[title]`…). |
| Redirect | 301s from the old site's addresses, and from an alias that changes. |
| Metatag (+ Open Graph) | Description and social sharing tags. |
| Simple XML Sitemap | `/sitemap.xml`. |
| Search API (+ Database Search) | The index behind `/recherche`. |
| Webform | The contact form at `/contact`. |
| Rabbit Hole | Partner terms have no page: they redirect to `/partenaires`. |
| Admin Toolbar | Drop-down admin menu. |

`tvshow_core` keeps a small built-in equivalent of each feature and uses it
only when the module is absent, so a module that fails to install does not
take the site down. To see what the setup did, or to run it again:

```bash
ddev drush php:script scripts/configure_contrib.php
```

Each line starts with `OK` or `FAILED`.

## What is in the repo

| Path | What it is |
| --- | --- |
| `web/profiles/custom/tvshow` | Install profile: core modules, text formats, default theme. |
| `web/modules/custom/tvshow_core` | Content model (config), pages and listings, URL patterns, search, RSS, sitemap, sharing tags, partner link check, old-address redirects, content importer. |
| `web/themes/custom/tvbase` | Base theme. Plain CSS driven by design tokens, small vanilla JS (menu, lightbox, click-to-load videos). |
| `web/themes/custom/sgu` | stargateuniverse.fr identity: tokens, self-hosted Barlow fonts, header banner, bronze footer tile. |
| `web/themes/custom/sgp` | stargate-pegasus.com: its own page chrome (bevelled navy header, mega menu, phone drawer), templates for every page, self-hosted Exo 2 and Source Sans 3. |
| `content/sgu/content.json` | Content pack extracted from the old site's archive. |
| `content/sgp/content.json` | Content pack of stargate-pegasus.com, extracted from the old PHP site and its database. |
| `scripts/` | Archive extractors (`extract_wp_archive.py`, `sgp/extract.py`), content import, config builders, a Drush-free script runner. |

## Local install with DDEV

```bash
ddev start
ddev composer install
ddev drush site:install tvshow --site-name="Stargate Universe" -y

# The old site's archive (pictures are read from it during the import).
git clone https://github.com/pjoulot/stargateuniverse content/sgu/archive

ddev drush php:script scripts/import_content.php -- \
  /var/www/html/content/sgu/content.json /var/www/html/content/sgu/archive
ddev launch
```

The import is safe to run again: it updates what it created. A pack that
describes the menus (stargate-pegasus.com does) replaces the main and footer
menus at each import.

### stargate-pegasus.com

The pack refers to pictures by their path in the old PHP site
(`Templates/Images/…`), so the import reads them from a copy of that site:

```bash
mkdir -p content/sgp/archive
cp -r ~/personal/stargate-pegasus/Templates content/sgp/archive/
ddev drush site:install tvshow --site-name="Stargate Pegasus" -y
ddev drush php:script scripts/import_content.php -- \
  /var/www/html/content/sgp/content.json /var/www/html/content/sgp/archive
```

To rebuild the pack from the old site and its database dump (no Drupal needed):

```bash
python3 scripts/sgp/extract.py ~/personal/stargate-pegasus <dump.sql or dump.gz>
```

It reads the PHP dispatchers and `.htaccess` to list every public address,
turns each template into clean HTML (facts become "Libellé : valeur" lines,
`[gras]`… BBCode becomes HTML), and writes the old addresses with each item.
Members, private messages and news comments are not part of the pack.

French interface strings (admin screens, a few core labels) come from
drupal.org and are not bundled. To add them:

```bash
ddev drush en locale -y && ddev drush locale:check && ddev drush locale:update
```

## Pages

| Address | Page |
| --- | --- |
| `/` | Featured news, latest news, latest wiki entries. |
| `/actualites`, `/actualites/rubrique/…`, `/tags/…` | News listings, 12 per page. |
| `/actualites/…` | One article. |
| `/episodes` | Episode guide. On a one-show site this is the series page. |
| `/sgu`, `/sgu/saison-1`, `/sgu/saison-1/air-1-3` | Series, season, episode. The prefix is the series abbreviation. |
| `/wiki`, `/wiki/personnages`, `/wiki/personnages/…` | Wiki index, rubric (`?serie=sga`, `?lettre=b`), entry. The prefix is the `wiki_path` setting (`/encyclopedie` on stargate-pegasus.com). |
| `/casting`, `/personnalites`, `/personnalites/…`, `/acteurs` | Cast, people index, one person, actors (`?serie=sg1`). |
| `/produits-derives`, `/produits-derives/dvd`, `/jeux-video/…` | Products by type (two levels), `?serie=` to keep one series. |
| `/partenaires`, `/contact`, `/recherche` | Partners, contact form, search. |
| `/actualites/rss.xml`, `/sitemap.xml` | Feeds. |

## Listings are Views

Every list on the site is a display of a view tagged `tvshow`
(`/admin/structure/views`): change the sort, the number of items, the filters
or the "no results" text there.

| View | Displays |
| --- | --- |
| `tvshow_news` | Page `/actualites`, feed `/actualites/rss.xml`, rubric, front page "à la une" and latest news (and "lead" + "more" for themes that open with one news), 404 page. |
| `tvshow_tagged` | Content of a tag. |
| `tvshow_wiki` | Latest entries, front page block, rubric, cast characters. |
| `tvshow_people` | Page `/personnalites`, by job (and series), crew on `/casting`. |
| `tvshow_products` | Products of a type and its sub-types (and series). |
| `tvshow_episodes` | Episodes of a season. |
| `tvshow_terms` | Series, seasons of a series, wiki rubrics, partners. |
| `tvshow_search` / `tvshow_search_api` | Results of `/recherche?s=…` (database fallback / Search API). |

Rows are entities rendered in the view modes `card`, `line`, `row`, `feature`
and `role`; `tvshow-card.html.twig` prints them. A display's "CSS class" is put
on the wrapper of its rows and names the grid (`tv-grid tv-grid--tiles`,
`tv-newslist`…). Composite pages (front, wiki, casting, series, season, term
pages) are small controllers that embed the displays. The shipped views are
generated by `scripts/build_views.php`.

Addresses are generated from titles by Pathauto patterns (ids `tv_*`) and
follow title changes; the old alias then redirects to the new one.

Old addresses (`/my-post/` on stargateuniverse.fr,
`/stargate-atlantis-saison-1-episode-14.html` or `/news-501-92-titre.html` on
stargate-pegasus.com) redirect to their new home with a 301: exact addresses
come from the content pack, addresses with a variable part from its
`legacy_patterns`, and old picture addresses (`/Templates/Images/…`) go to the
imported file.

## Editing

Log in at `/user/login`. Content types: Actualité, Fiche du wiki, Épisode,
Personnalité, Produit dérivé, Page. Footer text, tagline, social links, the
name of the wiki and the advertising code of the ad slots are under
Configuration → System → TV Show. The header banner is a theme setting.

"Fiche" fields (wiki entries, people, products, series, TV films) hold one
line per fact, `Libellé : valeur`; they are shown as a table of facts.

On stargate-pegasus.com the main menu is a mega menu with three levels:
section → column → links. A section's description is its introduction and the
label of its "see all" link, separated by `|`
(`Toutes les nouvelles de la franchise. | Toutes les actualités`). TV films are
the episodes of a "Téléfilms" season numbered 99.

An article is featured on the front page when "Promoted to front page" is
ticked; the two most recent promoted articles are shown.

## Adding the next site

1. Copy `web/themes/custom/sgu` to a new sub-theme and change `css/identity.css`
   (colours, fonts) and `images/`.
2. Produce a content pack (`content/<site>/content.json`) with `"theme"` set to
   the new sub-theme. `scripts/extract_wp_archive.py` is the SGU extractor; the
   three legacy-CMS sites need their own extractor writing the same format.
3. Install the profile on a new database and run the import.

A site that covers several shows needs nothing special: add more terms to the
"Série" vocabulary. `/episodes` then lists the series. A site whose design
departs from the base theme (stargate-pegasus.com) overrides the module's
templates in its sub-theme: see `web/themes/custom/sgp/templates`.

## Updating a site already in production

After pulling new code, run the database updates **before** clearing caches:

```bash
ddev drush updb -y && ddev drush cr
```

`tvshow_core_update_11001` adds what came with stargate-pegasus.com (products,
facts fields, first appearances, series of people, listing filters). It
replaces the `tvshow_news`, `tvshow_wiki` and `tvshow_people` views by the
module's versions: changes made to them in the UI are lost.

## Changing the content model

`web/modules/custom/tvshow_core/config/install` is generated. Edit
`scripts/build_config.php`, then on a scratch site:

```bash
ddev drush php:script scripts/build_config.php
```

## Regenerating the SGU content pack

```bash
python3 scripts/extract_wp_archive.py content/sgu/archive content/sgu/episodes.json content/sgu/content.json
```

Needs Python 3 with `beautifulsoup4` and `lxml`. Reader comments of the old
site are not extracted.

## Content packs

A pack is one JSON file per site. All keys are optional except `site` and
`series`.

| Key | What it holds |
| --- | --- |
| `site` | `name`, `slogan`, `theme`, `footer_text`, `description`, `social`, `wiki_path`, `wiki_label`. |
| `series` | One object (one-show site) or a list: `name`, `abbreviation` (used in addresses), `dates`, `creators`, `image`, `description` (HTML), `facts`, `legacy`. |
| `seasons` | `serie` (abbreviation), `number`, `title`, `first_aired`, `last_aired`, `legacy`. |
| `episodes` | `serie`, `season`, `number`, `title_fr`, `title_original`, `air_date`, `duration` (minutes), `audience`, `directors`, `writers`, `guest_cast`, `synopsis`, `body`, `facts`, `teaser`, `image`, `legacy`. |
| `people` | `name`, `slug`, `jobs`, `series`, `body`, `facts`, `image`, `legacy`. Directors and writers of the episodes are added automatically. |
| `characters` | One-show packs: a character and the actor who plays it. |
| `wiki_categories`, `wiki` | Rubrics; entries with `title`, `slug`, `category`, `serie`, `body`, `facts`, `appearance` (episodes), `tags`, `image`, `legacy`. |
| `product_types`, `products` | Two-level types; products with `title`, `slug`, `type`, `series`, `body`, `facts`, `image`, `legacy`. |
| `article_categories`, `articles` | Rubrics; news with `title`, `slug`, `date` (timestamp or `Y-m-d`), `category`, `summary`, `body`, `image`, `author`, `legacy`. |
| `pages`, `partners` | Free pages (`slug`, `title`, `body`); partner sites (`name`, `url`, `description`). |
| `menus` | `main` and `footer` trees: `title`, `ref`, `description`, `children`. |
| `redirects`, `legacy_patterns`, `aliases` | Old address → reference; regular expressions for old addresses; extra aliases (`/encyclopedie` for the wiki). |

Pictures are paths relative to the pictures folder given to the import. In
HTML, `src="archive:…"` points at such a picture and `href="legacy:/old.html"`
at an old address: the import rewrites both. References (`ref`, redirect
targets) are `serie:sga`, `season:sga:2`, `page:faq`, `category:Vaisseaux`,
`product_type:DVD`, `news_category:Acteurs`, `job:Acteur`, `route:<name>` or
`internal:/path`, optionally followed by `?query`.
