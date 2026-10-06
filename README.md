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
| `content/sgu/content.json` | Content pack extracted from the old site's archive. |
| `scripts/` | Archive extractor, content import, config builder, a Drush-free script runner. |

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

The import is safe to run again: it updates what it created.

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
| `/wiki`, `/wiki/personnages`, `/wiki/personnages/…` | Wiki index, rubric, entry. |
| `/casting`, `/personnalites`, `/personnalites/…` | Cast, people index, one person. |
| `/partenaires`, `/contact`, `/recherche` | Partners, contact form, search. |
| `/actualites/rss.xml`, `/sitemap.xml` | Feeds. |

Addresses are generated from titles by Pathauto patterns (ids `tv_*`) and
follow title changes; the old alias then redirects to the new one.

Old addresses of the WordPress site (`/my-post/`, `/equipe/…`, `/episodes/…`,
`/tag/…`) redirect to their new home with a 301.

## Editing

Log in at `/user/login`. Content types: Actualité, Fiche du wiki, Épisode,
Personnalité, Page. Footer text, tagline and social links are under
Configuration → System → TV Show. The header banner is a theme setting.

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
"Série" vocabulary. `/episodes` then lists the series.

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
