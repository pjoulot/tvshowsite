#!/usr/bin/env bash
# Fetches from the Wayback Machine what the archive dump lacks: small
# thumbnails of lost pictures, a few stray files, and page snapshots to look
# for pictures hosted elsewhere. Run from the repo root on a machine with open
# internet access:  bash scripts/fetch_wayback_extras.sh
# Files land in content/sgu/wayback/ (safe to re-run: existing files are kept).
set -u
cd "$(dirname "$0")/.."
dest=content/sgu/wayback
ok=0; failed=0
get() {
  local file="$dest/$1"
  [ -s "$file" ] && { ok=$((ok+1)); return; }
  mkdir -p "$(dirname "$file")"
  if curl -sSfL --retry 3 --retry-delay 5 --max-time 90 -o "$file" "https://web.archive.org/web/$2id_/$3"; then ok=$((ok+1)); else rm -f "$file"; failed=$((failed+1)); echo "failed: $1"; fi
  sleep 1
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
echo "Done: $ok fetched or already there, $failed failed. Files are in $dest/"
