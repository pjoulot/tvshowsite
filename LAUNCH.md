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

- [ ] Episode pictures: run `scripts/fetch_wayback_extras.sh`, hand the archive
      back, re-import. Target: 40 of 40 episodes with a picture.
- [ ] Decide on synopses. 35 of 40 episodes have none. Either write them
      before launch, or launch with facts only (cast, crew, dates, audience,
      related news) and add them over time. **Decision needed.**
- [ ] A "Mentions légales" page: publisher, host, contact, and the fan-site
      disclaimer. Required for a French site. I can draft it once you give me
      the publisher and host details.
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

## Decisions I need from you

1. Launch with or without episode synopses.
2. Analytics: none, or a privacy-friendly one (Matomo, Plausible). With none
   there is no cookie banner to add.
3. Comments: the old site had them. Not rebuilt. Keep it that way?
4. Canonical host: `www.stargateuniverse.fr` or `stargateuniverse.fr`.
5. Who publishes the site legally (name for the legal page) and which host.

## After launch

stargate-origins.com next, then the three sites on the legacy CMS
(green-arrow-france.fr, the-flash-france.fr, stargate-pegasus.com), which need
their code and database dumps.
