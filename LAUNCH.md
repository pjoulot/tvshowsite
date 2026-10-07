# Launch plan — stargateuniverse.fr

State on 6 October 2026: the site is built and runs locally on DDEV with the
contrib modules (Pathauto, Redirect, Metatag, Simple Sitemap, Search API,
Webform, Rabbit Hole, Admin Toolbar). Content comes from the archive import.
What follows is everything between that and the site being live, in order.
`TODO.md` keeps the full list, including what can wait until after launch.

## 1. Confirm the build (half a day)

Nothing here is expected to fail; none of it has been checked on a real install.

- [ ] Paste the `OK` / `FAILED` lines printed by `drush site:install`.
- [ ] `/recherche?s=rush` returns results, and `/admin/config/search/search-api`
      shows the index filled (so the search runs on Search API, not the fallback).
- [ ] View the source of one article, one episode and the front page: exactly
      one `<meta name="description">` and one `<link rel="canonical">` each.
- [ ] `/sitemap.xml` lists content and rubric pages.
- [ ] Send the contact form once and receive the e-mail. *(Form confirmed on
      Webform; delivery not tested.)*
- [ ] Check the header in Firefox and Chrome (search field, menu, mobile menu).

## 2. Content that must be there on day one (1–2 days, mostly yours)

- [x] Episode pictures: 40 of 40 (31 from the archive, 9 stills from TVmaze).
- [ ] Episode photo sets: run `scripts/fetch_gateworld_promos.sh` (about 1,500
      promotional and behind-the-scenes photos from the GateWorld gallery, kept
      out of the repo), then re-import.
- [ ] Synopses: 35 of 40 episodes to write, in `content/sgu/synopses.md`.
- [ ] Legal pages: fill the bracketed placeholders in
      `content/sgu/pages/mentions-legales.html` and
      `politique-de-confidentialite.html`.
- [ ] Analytics and cookie banner, once the tool is chosen.
- [ ] Front-page description (*Réglages du site TV Show*) and social links, or
      leave the social links empty.
- [ ] `/partenaires`: add partners or remove the menu link.
- [ ] Rename one of each pair of the three duplicate article titles.

Can wait: the 202 articles without a picture, short wiki entries, crew
biographies, new wiki rubrics, dead video embeds.

## 3. Design sign-off (half a day)

- [ ] Go through the built pages (design canvas or the local site) and list
      what to change. Known open points: header photo resolution, the ring
      placeholder for content without a picture, favicon (old 32 px icon only).
- [ ] Mobile pass on a real phone.

## 4. Production setup (1 day)

- [ ] VPS: PHP 8.3 with OPcache, MariaDB 10.11, Nginx or Apache, Composer,
      Git. A deploy user with access to the repo.
- [ ] Fix GitHub access: this machine pushes as `philippemellenger`, which
      cannot write to `pjoulot/tvshowsite`.
- [ ] `settings.php` for production: database, `trusted_host_patterns`, hash
      salt, private files path, `config_sync_directory`.
- [ ] Export the configuration (`drush cex`) and commit `config/sync`, so later
      changes deploy with `drush deploy`.
- [ ] HTTPS certificate (Let's Encrypt) and redirect of `http://` and of the
      bare domain or `www.` to one canonical host.
- [ ] Cron every hour (`drush cron`): search index, sitemap, partner link check.
- [ ] Outgoing e-mail: an SMTP account or the host's mail relay, so the contact
      form is delivered and not marked as spam.
- [ ] Backups: nightly database dump and `sites/default/files`, kept off the
      server. Test one restore.
- [ ] Turn on CSS/JS aggregation and page caching; errors hidden from visitors.
- [ ] French interface: `drush locale:check && drush locale:update`.

## 5. Go live (half a day)

- [ ] Install on the VPS and run the import there, or copy the local database
      and files. Second option is faster and keeps node ids.
- [ ] Change the admin password; create your editor account.
- [ ] Before DNS: test through the hosts file. Front page, one page of each
      type, search, contact, RSS, sitemap, a 404.
- [ ] Old addresses: test ten WordPress URLs of each kind (`/my-post/`,
      `/tag/…`, `/equipe/…`, `/episodes/…`, `/category/…`, a feed URL). They
      must answer 301 to the new page.
- [ ] Point DNS. Lower the TTL the day before.
- [ ] `robots.txt` allows crawling and names the sitemap.
- [ ] Search Console: verify the domain, submit the sitemap.
- [ ] Lighthouse on the live front page and one article.

## 6. First week

- [ ] Watch the logs (`/admin/reports/dblog`) for 404s: each frequent one is a
      missing redirect.
- [ ] Check the search index and sitemap were updated by cron.
- [ ] Remove the built-in fallbacks (aliases, meta tags, sitemap, search,
      contact form) now that the contrib modules are proven. Code cleanup, no
      visible change.

## Decisions (6 October)

1. **Synopses are required for launch.** Philippe writes them in
   `content/sgu/synopses.md` (one block per episode, 35 to write); the import
   publishes them. A synopsis typed directly on the site is also kept.
2. **Analytics with a cookie banner.** Tool still to choose, see below.
3. **Design tweaks and a mobile check before launch.** First mobile pass done
   (news lists are compact rows on phones); Philippe's list of tweaks to come.
4. **Canonical host: `www.stargateuniverse.fr`**; the bare domain and `http://`
   redirect to it. Set up with the server, last.
5. **Legal pages.** Drafts of "Mentions légales" and "Politique de
   confidentialité" are in `content/sgu/pages/` and linked in the footer. To
   fill in: publisher name, host name and address, retention periods, and the
   cookies paragraph once the analytics tool is chosen. Have the wording checked
   if in doubt: these are sensible drafts, not legal advice.

## Still to decide

- **Which analytics.** Matomo (self-hosted on the VPS, free) or Plausible
  (hosted, paid) can run without cookies, which in France means no consent
  banner is required for them. Google Analytics needs the banner and consent
  before loading. If a banner is wanted regardless (YouTube and Dailymotion
  embeds set cookies when played), the `klaro` module handles it.
- **Comments:** left out, as now, unless said otherwise.

## After launch

stargate-origins.com next, then the three sites on the legacy CMS
(green-arrow-france.fr, the-flash-france.fr, stargate-pegasus.com), which need
their code and database dumps.
