# To do — stargateuniverse.fr

Result of the full review of 6 October 2026: every page of the sitemap crawled
(486 addresses: status, title, description, canonical, sharing tags, headings,
images, internal links) and Lighthouse (mobile) on six page types. The review
ran on a workspace copy **without the contrib modules**; anything that depends
on them is listed under "Not verified yet".

## Fixed during the review

- Images without `width`/`height`: header banner and every picture inside
  article text now carry their size (was 1,191 images).
- Meta description on every page (was missing on 88: front page, listings,
  rubrics, people and episodes without text). Front-page text is editable under
  *Réglages du site TV Show*.
- Canonical link and sharing tags (`og:*`, `twitter:card`) on listings, rubric
  and term pages; pages without a picture share the site banner.
- Layout shift: the mobile menu flashed open and pushed the page down, and
  text moved when the fonts arrived. Lighthouse CLS went from 0.3–0.5 to under
  0.01, and performance from 76–80 to 96–97.
- Front-page title is "Stargate Universe – Le site francophone" (was
  "Accueil | …"); paginated listings are titled "… – page 2".
- Heading order on listing pages (h1 → h3 jump).
- One dead link in an article (to a tag that no longer exists).
- Footer texture and banner served as WebP (110 KB → 35 KB, 73 KB → 37 KB).
- Person page: picture box aligned to the top, hidden when empty.
- Rush and Telford portraits replaced with the pictures supplied.

Lighthouse after the fixes: accessibility 100, best practices 100, SEO 100 on
all six pages, performance 96–97 on the local test server.

## Not verified yet — needs the real install (DDEV)

- [ ] `ddev composer update -W` resolves with the constraints in
      `composer.json` (never run; `rabbit_hole` and `webform` are on betas).
      Commit the resulting `composer.lock`.
- [ ] `drush site:install` prints `OK` for Pathauto, Metatag, Simple Sitemap,
      Search API, Webform and Rabbit Hole. That setup code has never run
      against the modules.
- [ ] `/recherche?s=…` uses the Search API view (`tvshow_search_api`) once the
      index is filled; until then it falls back to the database view.
- [ ] With Metatag installed, check one article, one episode and the front
      page still have exactly one description and one canonical link.
- [ ] `/contact` shows the Webform and its e-mail reaches the site address.
- [ ] `/sitemap.xml` is served by Simple Sitemap and lists content and terms.

## Content gaps

- [ ] 35 of 40 episodes have no synopsis (the archive only had five). To write
      or source.
- [ ] 9 episodes have no picture.
- [ ] 202 of 376 articles have no picture; their thumbnails were never
      archived. Listings show a placeholder.
- [ ] 12 articles and 1,380 full-size pictures of the old site are not in the
      archive and not on the Wayback Machine. Considered lost.
- [ ] Short wiki entries: Matthew Scott, Chloe Armstrong, Colonel Telford. No
      biography for Elyse Levesque. Directors and writers have no biography or
      picture.
- [ ] The wiki has one rubric (Personnages). Ships, technologies, planets and
      races were planned on the old site and never written.
- [ ] 71 video embeds point to YouTube, Dailymotion and syfy.com from
      2009–2011; many are probably gone. Not checked: needs a link check from a
      machine with open internet access.
- [ ] 65 links to tvsubtitles.net and 14 to stargate.mgm.com: same remark.
- [ ] Three pairs of articles share a title ("Trois nouveaux webisodes
      disponibles"…). Rename one of each pair.
- [ ] 81 article titles make a page title over 70 characters; search engines
      cut them. Cosmetic.
- [ ] `/partenaires` is empty.
- [ ] Episode audience figures: 1x03 carries the pilot's figure (aired together).

## Design

- [ ] Review of the 20 built pages on the design canvas (not done yet).
- [ ] Header photo: the current banner is a 980 × 240 picture with the logo
      patched out by hand. A larger, logo-free source would look sharper on wide
      screens.
- [ ] Rush and Telford portraits are enlarged from small sources (584 and 755
      px wide); fine in the casting grid, soft at full size.
- [ ] Placeholder for content without a picture (the ring): confirm or replace.
- [ ] Favicon is the old 32 px `.ico` only; no SVG or touch icon.

## Site features

- [ ] French interface translations (admin screens, a few core labels) are not
      bundled; `drush locale:update` on the server.
- [ ] Legal page ("Mentions légales") and cookie/privacy text: no page exists.
- [ ] Social links are empty in the settings.
- [ ] Analytics: none installed. Decide whether one is wanted.
- [ ] Comments: the old site had them; not rebuilt (and the forum is out of
      scope by decision).
- [ ] Episode pages, person pages (credited episodes, roles) and the fallback
      sitemap still use small custom queries; every listing page is a view.
- [ ] Built-in fallbacks (aliases, meta tags, sitemap, search, contact form) to
      remove once the contrib modules are confirmed.
- [ ] CSS and JS aggregation and page cache are off on the test server; turn on
      in production (`/admin/config/development/performance`).

## Deployment

- [ ] VPS: PHP 8.3, MariaDB, web server config, HTTPS, cron, backups.
- [ ] `config/sync` is empty: export the configuration once the site is
      settled, then deploy with `drush deploy`.
- [ ] Redirects from the old WordPress addresses (430 created by the import):
      spot-check on the live domain.
- [ ] `robots.txt` and the sitemap address for the live domain.

## Next sites

- [ ] stargate-origins.com (single page originally).
- [ ] green-arrow-france.fr, the-flash-france.fr, stargate-pegasus.com: need the
      legacy CMS code and database dumps.
