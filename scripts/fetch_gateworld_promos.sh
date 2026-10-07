#!/usr/bin/env bash
# Fetches the promotional and behind-the-scenes photos of every episode from
# the GateWorld gallery (https://www.gateworld.net/gallery/, Stargate Universe
# > Promotional Photos): about 1,500 pictures, several hundred MB.
#
# Run from the repo root:  bash scripts/fetch_gateworld_promos.sh
# Takes 45 to 60 minutes: it waits between requests so as not to load their
# server. Safe to stop and re-run; pictures already there are skipped.
# Pictures land in content/sgu/gateworld/<season>-<episode>/ (not committed:
# too large). The content import picks them up from there.
set -u
cd "$(dirname "$0")/.."
base=https://www.gateworld.net/gallery
dest=content/sgu/gateworld
agent="Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0"
got=0; had=0; failed=0

fetch() {  # fetch <url> <output file>; waits and retries when the server says "slow down"
  local try code
  for try in 1 2 3 4; do
    code=$(curl -sSL --max-time 180 -A "$agent" -e "$base/" -o "$2" -w '%{http_code}' "$1" 2>/dev/null)
    [ "$code" = 200 ] && [ -s "$2" ] && return 0
    rm -f "$2"
    [ "$code" = 404 ] && return 1
    sleep $((try * 60))
  done
  return 1
}

album() {  # album <gallery album id> <season> <episode>
  local id=$1 dir page list path file target n=0
  dir=$(printf '%s/%d-%02d' "$dest" "$2" "$3")
  mkdir -p "$dir"
  list=$(mktemp)
  for page in 1 2 3 4 5 6 7 8; do
    fetch "$base/thumbnails.php?album=$id&page=$page" "$list.html" || break
    grep -o 'data-echo="albums/[^"]*"' "$list.html" | sed 's/^data-echo="//; s/"$//; s|/thumb_|/|' > "$list.page"
    sleep 2
    # A page past the last one repeats pictures already listed.
    [ -s "$list.page" ] && ! grep -qxFf "$list" "$list.page" || break
    cat "$list.page" >> "$list"
  done
  while IFS= read -r path; do
    file=${path##*/}
    case "$path" in *behind-the-scenes*|*behind_the_scenes*|*-bts*) target="$dir/bts/$file" ;; *) target="$dir/$file" ;; esac
    n=$((n+1))
    if [ -s "$target" ]; then had=$((had+1)); continue; fi
    mkdir -p "$(dirname "$target")"
    if fetch "$base/$path" "$target"; then got=$((got+1)); else failed=$((failed+1)); echo "  failed: $path"; fi
    sleep 1
  done < "$list"
  printf '%dx%02d: %d pictures\n' "$2" "$3" "$n"
  rm -f "$list" "$list.html" "$list.page"
}

album 712 1 1
album 891 1 2
album 892 1 3
album 894 1 4
album 896 1 5
album 748 1 6
album 900 1 7
album 903 1 8
album 905 1 9
album 907 1 10
album 909 1 11
album 910 1 12
album 911 1 13
album 912 1 14
album 918 1 15
album 920 1 16
album 922 1 17
album 925 1 18
album 927 1 19
album 929 1 20
album 931 2 1
album 932 2 2
album 937 2 3
album 939 2 4
album 940 2 5
album 943 2 6
album 944 2 7
album 945 2 8
album 946 2 9
album 947 2 10
album 953 2 11
album 954 2 12
album 956 2 13
album 959 2 14
album 960 2 15
album 962 2 16
album 964 2 17
album 966 2 18
album 968 2 19
album 970 2 20

echo "Done: $got downloaded, $had already there, $failed failed. Pictures are in $dest/ ($(du -sh "$dest" | cut -f1))."
