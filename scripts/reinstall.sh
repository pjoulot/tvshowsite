#!/usr/bin/env bash
# Workspace helper: wipe the local SQLite site and reinstall from the profile.
set -e
cd "$(dirname "$0")/.."
chmod -R u+w web/sites/default 2>/dev/null || true
rm -rf web/sites/default/files web/sites/default/settings.php
vendor/bin/dr install tvshow --site-name="${1:-TV Show}" --password=admin --langcode=en 2>&1 | grep -v '^$' | tail -3
chmod u+w web/sites/default web/sites/default/settings.php
