#!/usr/bin/env bash
# Fetches from the Wayback Machine what the archive dump lacks: small
# thumbnails of lost pictures, a few stray files, and page snapshots to look
# for pictures hosted elsewhere. Run from the repo root on a machine with open
# internet access:  bash scripts/fetch_wayback_extras.sh
# Files land in content/sgu/wayback/ (safe to re-run: existing files are kept).
# Takes five to ten minutes: the Wayback Machine throttles fast clients.
set -u
cd "$(dirname "$0")/.."
dest=content/sgu/wayback
ok=0; failed=0; missing=0
get() {
  local file="$dest/$1" try code
  [ -s "$file" ] && { ok=$((ok+1)); return; }
  mkdir -p "$(dirname "$file")"
  # The Wayback Machine refuses connections for a while after a burst of
  # requests: go slowly, and wait longer after each refusal. A plain "not
  # archived" answer (HTTP 404) is final and not retried.
  for try in 1 2 3 4; do
    curl -sSfL --max-time 120 -o "$file" "https://web.archive.org/web/$2id_/$3" 2>/dev/null; code=$?
    if [ $code -eq 0 ] && [ -s "$file" ]; then ok=$((ok+1)); echo "got: $1"; sleep 5; return; fi
    rm -f "$file"
    if [ $code -eq 22 ]; then missing=$((missing+1)); sleep 5; return; fi
    sleep $((try * 45))
  done
  failed=$((failed+1)); echo "failed: $1"
}
get 'thumbs/wp-content/uploads/2009/08/2.jpg.png' 20150201050807 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2009/08/2.jpg&h=128&w=188&zc=1'
get 'thumbs/wp-content/uploads/2011/05/4.jpg.png' 20110701122605 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2011/05/4.jpg&h=128&w=188&zc=1'
get 'thumbs/wp-content/uploads/2011/05/S3.jpg.png' 20110701122611 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2011/05/S3.jpg&h=128&w=188&zc=1'
get 'thumbs/wp-content/uploads/2011/07/ah.jpg.png' 20150201051306 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2011/07/ah.jpg&h=128&w=188&zc=1'
get 'files/wp-content/gallery/kit-de-presse/thumbs/thumbs_01-stargate-universe-presskit-1_0.jpg' 20110701195347 'http://www.stargateuniverse.fr/wp-content/gallery/kit-de-presse/thumbs/thumbs_01-stargate-universe-presskit-1_0.jpg'
get 'files/wp-content/gallery/kit-de-presse/thumbs/thumbs_01-stargate-universe-presskit-4_0.jpg' 20110701195247 'http://www.stargateuniverse.fr/wp-content/gallery/kit-de-presse/thumbs/thumbs_01-stargate-universe-presskit-4_0.jpg'
get 'files/wp-content/gallery/kit-de-presse/thumbs/thumbs_02series-synopsis_0.jpg' 20110701195927 'http://www.stargateuniverse.fr/wp-content/gallery/kit-de-presse/thumbs/thumbs_02series-synopsis_0.jpg'
get 'files/wp-content/themes/church_40/images/actualites.jpg' 20150201041404 'http://www.stargateuniverse.fr/wp-content/themes/church_40/images/actualites.jpg'
get 'files/wp-content/uploads/2010/03/1006796221151fb349558e2488f9cdb51-150x150.jpg' 20110702013052 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/1006796221151fb349558e2488f9cdb51-150x150.jpg'
get 'files/wp-content/uploads/2010/03/39a6f938f8f47f2c328d7921dd61794c1-150x150.jpg' 20110702012928 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/39a6f938f8f47f2c328d7921dd61794c1-150x150.jpg'
get 'files/wp-content/uploads/2010/03/4457c2a21c859bfa969428545f863ce21-150x150.jpg' 20110702012754 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/4457c2a21c859bfa969428545f863ce21-150x150.jpg'
get 'files/wp-content/uploads/2010/03/5441af875c2f7d731b5ca1f9714691781-150x150.jpg' 20110702012923 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/5441af875c2f7d731b5ca1f9714691781-150x150.jpg'
get 'files/wp-content/uploads/2010/03/721d912499211da5f87e23e8beea05061-150x150.jpg' 20110702012739 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/721d912499211da5f87e23e8beea05061-150x150.jpg'
get 'files/wp-content/uploads/2010/03/a30bfe025021099262a9cef5b0db5aff1-150x150.jpg' 20110702012717 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/a30bfe025021099262a9cef5b0db5aff1-150x150.jpg'
get 'files/wp-content/uploads/2010/03/af9ed56ae54dd14abc543d43d14c35cd1-150x150.jpg' 20110702012639 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/af9ed56ae54dd14abc543d43d14c35cd1-150x150.jpg'
get 'files/wp-content/uploads/2010/03/b0b7ecc71637d421ec0c90d748e80dd41-150x150.jpg' 20110702012709 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/b0b7ecc71637d421ec0c90d748e80dd41-150x150.jpg'
get 'files/wp-content/uploads/2010/03/b7bf5b480978181e67fa92ed8c2326f81-150x150.jpg' 20110702013138 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/b7bf5b480978181e67fa92ed8c2326f81-150x150.jpg'
get 'files/wp-content/uploads/2010/03/e175985f9636648f5b9b5724dede306c1-150x150.jpg' 20110702012822 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/e175985f9636648f5b9b5724dede306c1-150x150.jpg'
get 'files/wp-content/uploads/2010/03/ede2b8c9d9bc4727634714a8a2a065c81-150x150.jpg' 20110702012908 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/ede2b8c9d9bc4727634714a8a2a065c81-150x150.jpg'
get 'files/wp-content/uploads/2010/03/f804f67cc1932a0e6e5814180d1f465d1-150x150.jpg' 20110702013125 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/f804f67cc1932a0e6e5814180d1f465d1-150x150.jpg'
get 'files/wp-content/uploads/2010/04/b133f65fbc09835a0db7094742f70b691-150x150.jpg' 20110701233725 'http://www.stargateuniverse.fr/wp-content/uploads/2010/04/b133f65fbc09835a0db7094742f70b691-150x150.jpg'
get 'html/home-20090908010251.html' 20090908010251 'http://www.stargateuniverse.fr:80/'
get 'html/home-20100130202650.html' 20100130202650 'http://www.stargateuniverse.fr:80/'
get 'html/home-20100304155132.html' 20100304155132 'http://www.stargateuniverse.fr:80/'
get 'html/home-20100406075836.html' 20100406075836 'http://www.stargateuniverse.fr:80/'
get 'html/home-20100510143938.html' 20100510143938 'http://www.stargateuniverse.fr:80/'
get 'html/home-20110108021719.html' 20110108021719 'http://www.stargateuniverse.fr/'
get 'html/home-20110902092641.html' 20110902092641 'http://www.stargateuniverse.fr:80/'
get 'html/home-20111025010709.html' 20111025010709 'http://www.stargateuniverse.fr:80/'
get 'html/home-20111124135627.html' 20111124135627 'http://www.stargateuniverse.fr:80/'
get 'html/home-20111225043342.html' 20111225043342 'http://www.stargateuniverse.fr:80/'
get 'html/home-20120410124027.html' 20120410124027 'http://www.stargateuniverse.fr:80/'
get 'html/home-20120608084203.html' 20120608084203 'http://www.stargateuniverse.fr:80/'
get 'html/home-20120808044550.html' 20120808044550 'http://www.stargateuniverse.fr/'
get 'html/home-20130125063648.html' 20130125063648 'http://www.stargateuniverse.fr:80/'
get 'html/home-20130404092709.html' 20130404092709 'http://www.stargateuniverse.fr/'
get 'html/home-20130624083218.html' 20130624083218 'http://www.stargateuniverse.fr:80/'
get 'html/home-20131208192458.html' 20131208192458 'http://www.stargateuniverse.fr/'
get 'html/home-20140214015932.html' 20140214015932 'http://www.stargateuniverse.fr/'
get 'html/home-20141006163450.html' 20141006163450 'http://www.stargateuniverse.fr:80/'
get 'html/home-20141225223659.html' 20141225223659 'http://www.stargateuniverse.fr/'
get 'html/home-20150720215523.html' 20150720215523 'http://www.stargateuniverse.fr:80/'

# Second pass: lead pictures named on the old front page. Each is tried as the
# small thumbnail and as the original file; most were never archived.
get 'thumbs/wp-content/uploads/2010/01/sgu-stargate-universe-4.jpg.png' 20100130202650 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/01/sgu-stargate-universe-4.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/01/sgu-stargate-universe-4.jpg' 20100130202650 'http://www.stargateuniverse.fr/wp-content/uploads/2010/01/sgu-stargate-universe-4.jpg'
get 'thumbs/wp-content/uploads/2010/01/sgu_101_airpart1_002.jpg.png' 20100130202650 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/01/sgu_101_airpart1_002.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/01/sgu_101_airpart1_002.jpg' 20100130202650 'http://www.stargateuniverse.fr/wp-content/uploads/2010/01/sgu_101_airpart1_002.jpg'
get 'thumbs/wp-content/uploads/2010/01/stargate-tca-panel-robert-carlyle-ming-na-david-blue-lou-diamond-phillips-brad-wright-robert-cooper.jpg.png' 20100130202650 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/01/stargate-tca-panel-robert-carlyle-ming-na-david-blue-lou-diamond-phillips-brad-wright-robert-cooper.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/01/stargate-tca-panel-robert-carlyle-ming-na-david-blue-lou-diamond-phillips-brad-wright-robert-cooper.jpg' 20100130202650 'http://www.stargateuniverse.fr/wp-content/uploads/2010/01/stargate-tca-panel-robert-carlyle-ming-na-david-blue-lou-diamond-phillips-brad-wright-robert-cooper.jpg'
get 'thumbs/wp-content/uploads/2010/01/stargate_universe_cast_image.jpg.png' 20100130202650 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/01/stargate_universe_cast_image.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/01/stargate_universe_cast_image.jpg' 20100130202650 'http://www.stargateuniverse.fr/wp-content/uploads/2010/01/stargate_universe_cast_image.jpg'
get 'thumbs/wp-content/uploads/2010/01/stargate_universe_gate_room2.jpg.png' 20100130202650 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/01/stargate_universe_gate_room2.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/01/stargate_universe_gate_room2.jpg' 20100130202650 'http://www.stargateuniverse.fr/wp-content/uploads/2010/01/stargate_universe_gate_room2.jpg'
get 'thumbs/wp-content/uploads/2010/01/up7.jpg.png' 20100130202650 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/01/up7.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/01/up7.jpg' 20100130202650 'http://www.stargateuniverse.fr/wp-content/uploads/2010/01/up7.jpg'
get 'thumbs/wp-content/uploads/2010/02/elyse_levesque.jpg.png' 20100304155132 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/02/elyse_levesque.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/02/elyse_levesque.jpg' 20100304155132 'http://www.stargateuniverse.fr/wp-content/uploads/2010/02/elyse_levesque.jpg'
get 'thumbs/wp-content/uploads/2010/02/nicholas_rush_012.jpg.png' 20100304155132 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/02/nicholas_rush_012.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/02/nicholas_rush_012.jpg' 20100304155132 'http://www.stargateuniverse.fr/wp-content/uploads/2010/02/nicholas_rush_012.jpg'
get 'thumbs/wp-content/uploads/2010/02/sgudvdfr.jpg.png' 20100304155132 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/02/sgudvdfr.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/02/sgudvdfr.jpg' 20100304155132 'http://www.stargateuniverse.fr/wp-content/uploads/2010/02/sgudvdfr.jpg'
get 'thumbs/wp-content/uploads/2010/02/stargate-universe-291-720px.jpg.png' 20100304155132 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/02/stargate-universe-291-720px.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/02/stargate-universe-291-720px.jpg' 20100304155132 'http://www.stargateuniverse.fr/wp-content/uploads/2010/02/stargate-universe-291-720px.jpg'
get 'thumbs/wp-content/uploads/2010/03/1006796221151fb349558e2488f9cdb51.jpg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/03/1006796221151fb349558e2488f9cdb51.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/03/1006796221151fb349558e2488f9cdb51.jpg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/1006796221151fb349558e2488f9cdb51.jpg'
get 'thumbs/wp-content/uploads/2010/03/9d4f039e0f0fe29b9ca05503589626491.jpg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/03/9d4f039e0f0fe29b9ca05503589626491.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/03/9d4f039e0f0fe29b9ca05503589626491.jpg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/9d4f039e0f0fe29b9ca05503589626491.jpg'
get 'thumbs/wp-content/uploads/2010/03/alien.jpg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/03/alien.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/03/alien.jpg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/alien.jpg'
get 'thumbs/wp-content/uploads/2010/03/ferreira.jpg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/03/ferreira.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/03/ferreira.jpg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/ferreira.jpg'
get 'thumbs/wp-content/uploads/2010/03/sguladies.jpg.png' 20100304155132 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/03/sguladies.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/03/sguladies.jpg' 20100304155132 'http://www.stargateuniverse.fr/wp-content/uploads/2010/03/sguladies.jpg'
get 'thumbs/wp-content/uploads/2010/04/26e050350a8d19e0b01aa0e0e3952c27.jpg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/04/26e050350a8d19e0b01aa0e0e3952c27.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/04/26e050350a8d19e0b01aa0e0e3952c27.jpg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/04/26e050350a8d19e0b01aa0e0e3952c27.jpg'
get 'thumbs/wp-content/uploads/2010/04/Julie-McNiven.jpg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/04/Julie-McNiven.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/04/Julie-McNiven.jpg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/04/Julie-McNiven.jpg'
get 'thumbs/wp-content/uploads/2010/04/normal_112_divided_09.jpeg.png' 20100406075836 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/04/normal_112_divided_09.jpeg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/04/normal_112_divided_09.jpeg' 20100406075836 'http://www.stargateuniverse.fr/wp-content/uploads/2010/04/normal_112_divided_09.jpeg'
get 'thumbs/wp-content/uploads/2010/05/1fc64f92aaf6d5728102c26115ad0a52.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/1fc64f92aaf6d5728102c26115ad0a52.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/1fc64f92aaf6d5728102c26115ad0a52.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/1fc64f92aaf6d5728102c26115ad0a52.jpg'
get 'thumbs/wp-content/uploads/2010/05/325abd0ebae9d3c91f0d1ba79be265d3.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/325abd0ebae9d3c91f0d1ba79be265d3.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/325abd0ebae9d3c91f0d1ba79be265d3.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/325abd0ebae9d3c91f0d1ba79be265d3.jpg'
get 'thumbs/wp-content/uploads/2010/05/6df67bcb7abbd515824b861c077a2bf7.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/6df67bcb7abbd515824b861c077a2bf7.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/6df67bcb7abbd515824b861c077a2bf7.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/6df67bcb7abbd515824b861c077a2bf7.jpg'
get 'thumbs/wp-content/uploads/2010/05/71953f29cd35da44277d6ee95d9bf648.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/71953f29cd35da44277d6ee95d9bf648.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/71953f29cd35da44277d6ee95d9bf648.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/71953f29cd35da44277d6ee95d9bf648.jpg'
get 'thumbs/wp-content/uploads/2010/05/a3fc83bf6d349d6fc15f4965ba014e22.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/a3fc83bf6d349d6fc15f4965ba014e22.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/a3fc83bf6d349d6fc15f4965ba014e22.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/a3fc83bf6d349d6fc15f4965ba014e22.jpg'
get 'thumbs/wp-content/uploads/2010/05/aa9aac99e54dcb9939d0a2642d9975d8.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/aa9aac99e54dcb9939d0a2642d9975d8.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/aa9aac99e54dcb9939d0a2642d9975d8.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/aa9aac99e54dcb9939d0a2642d9975d8.jpg'
get 'thumbs/wp-content/uploads/2010/05/d11b6f3bfb553a6c379a869c7824ec67.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/d11b6f3bfb553a6c379a869c7824ec67.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/d11b6f3bfb553a6c379a869c7824ec67.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/d11b6f3bfb553a6c379a869c7824ec67.jpg'
get 'thumbs/wp-content/uploads/2010/05/f4eb2341bf1903cac7c33d5779644d4c1.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/f4eb2341bf1903cac7c33d5779644d4c1.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/f4eb2341bf1903cac7c33d5779644d4c1.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/f4eb2341bf1903cac7c33d5779644d4c1.jpg'
get 'thumbs/wp-content/uploads/2010/05/f76dd4bc0b4122ba572e0773339a144c.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/f76dd4bc0b4122ba572e0773339a144c.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/f76dd4bc0b4122ba572e0773339a144c.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/f76dd4bc0b4122ba572e0773339a144c.jpg'
get 'thumbs/wp-content/uploads/2010/05/rush2.jpg.png' 20100510143938 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/05/rush2.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/05/rush2.jpg' 20100510143938 'http://www.stargateuniverse.fr/wp-content/uploads/2010/05/rush2.jpg'
get 'thumbs/wp-content/uploads/2010/11/31.jpeg.png' 20110108021719 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/11/31.jpeg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/11/31.jpeg' 20110108021719 'http://www.stargateuniverse.fr/wp-content/uploads/2010/11/31.jpeg'
get 'thumbs/wp-content/uploads/2010/11/fullsize-sgu0210-0122xe1.jpg.png' 20110108021719 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/11/fullsize-sgu0210-0122xe1.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/11/fullsize-sgu0210-0122xe1.jpg' 20110108021719 'http://www.stargateuniverse.fr/wp-content/uploads/2010/11/fullsize-sgu0210-0122xe1.jpg'
get 'thumbs/wp-content/uploads/2010/12/12.jpeg.png' 20110108021719 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/12/12.jpeg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/12/12.jpeg' 20110108021719 'http://www.stargateuniverse.fr/wp-content/uploads/2010/12/12.jpeg'
get 'thumbs/wp-content/uploads/2010/12/FullSize-sgu0210-0215xb.jpeg.png' 20110108021719 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2010/12/FullSize-sgu0210-0215xb.jpeg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2010/12/FullSize-sgu0210-0215xb.jpeg' 20110108021719 'http://www.stargateuniverse.fr/wp-content/uploads/2010/12/FullSize-sgu0210-0215xb.jpeg'
get 'thumbs/wp-content/uploads/2011/04/44.jpg.png' 20150720215523 'http://www.stargateuniverse.fr/wp-content/themes/church_40/tools/timthumb.php?src=http://www.stargateuniverse.fr/wp-content/uploads/2011/04/44.jpg&h=128&w=188&zc=1'
get 'files/wp-content/uploads/2011/04/44.jpg' 20150720215523 'http://www.stargateuniverse.fr/wp-content/uploads/2011/04/44.jpg'

# One flat archive next to the folder, easy to hand over.
tar czf content/sgu/wayback.tar.gz -C content/sgu wayback
echo "Done: $ok fetched or already there, $missing not archived, $failed failed. Result: content/sgu/wayback.tar.gz"
