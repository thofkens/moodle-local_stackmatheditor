#!/usr/bin/env bash
# This file is part of Moodle - https://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
#
# CI helper: fetches the release a site upgrades FROM - the newest version of this plugin in the
# Moodle plugins directory that is older than the commit under test - and unpacks it.
#
# The package is the one moodle.org hands to a site administrator, not something rebuilt from
# this repository: that is what is installed out there, and it does not depend on which branch a
# release was cut from. An earlier version of the upgrade job looked for the release in the git
# history and found nothing, because 1.2.2 was released from a branch of its own.
#
# Usage: fetch-previous-release.sh <directory of the commit under test> <destination directory>
#
# Environment (optional):
#   PLUGLIST_URL   the plugins directory API (https://download.moodle.org/api/1.3/pluglist.php)
#   COMPONENT      the plugin's component name (local_stackmatheditor)
#
# @copyright 2026 Ralf Erlebach
# @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -euo pipefail

new="${1:?directory of the commit under test}"
dest="${2:?destination directory}"
PLUGLIST_URL="${PLUGLIST_URL:-https://download.moodle.org/api/1.3/pluglist.php}"
COMPONENT="${COMPONENT:-local_stackmatheditor}"

fail () {
  echo "::error::$1"
  exit 1
}

current=$(sed -n 's/^\$plugin->version *= *\([0-9]\{10\}\);.*/\1/p' "$new/version.php")
[[ -n "$current" ]] || fail "No version found in $new/version.php."
echo "Commit under test: $COMPONENT $current"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# The list covers every plugin in the directory - some fifteen megabytes.
curl -sS --fail --location --retry 3 --retry-delay 5 --max-time 180 \
  -o "$work/pluglist.json" "$PLUGLIST_URL" \
  || fail "The Moodle plugins directory did not answer ($PLUGLIST_URL)."

# The newest published version that is older than the commit under test. "Older than", not "the
# newest": once this commit's own version is published, it is in the list too.
choice=$(python3 - "$work/pluglist.json" "$COMPONENT" "$current" <<'PY'
import json
import sys

path, component, current = sys.argv[1], sys.argv[2], int(sys.argv[3])
with open(path, encoding='utf-8') as handle:
    plugins = json.load(handle).get('plugins', [])

versions = [
    version
    for plugin in plugins if plugin.get('component') == component
    for version in plugin.get('versions', [])
    if int(version.get('version', 0)) < current and version.get('downloadurl')
]
if versions:
    best = max(versions, key=lambda version: int(version['version']))
    print(best['version'], best.get('release') or '?', best.get('downloadmd5') or '-', best['downloadurl'])
PY
)
[[ -n "$choice" ]] \
  || fail "The plugins directory lists no version of $COMPONENT older than $current."

read -r version release md5 url <<<"$choice"
echo "Previous release:  $COMPONENT $version ($release)"
echo "Package:           $url"

curl -sS --fail --location --retry 3 --retry-delay 5 --max-time 180 -o "$work/previous.zip" "$url" \
  || fail "The package of $COMPONENT $version could not be downloaded."

if [[ "$md5" != "-" ]]; then
  actual=$(md5sum "$work/previous.zip" | cut -d' ' -f1)
  [[ "$actual" == "$md5" ]] \
    || fail "The package of $COMPONENT $version has checksum $actual, the directory says $md5."
  echo "Checksum:          $md5, as the directory states"
fi

# The archive holds one directory, and its name is not ours to rely on.
mkdir "$work/unpacked"
unzip -q "$work/previous.zip" -d "$work/unpacked"
mapfile -t tops < <(find "$work/unpacked" -mindepth 1 -maxdepth 1)
[[ ${#tops[@]} -eq 1 && -d "${tops[0]}" ]] \
  || fail "The package of $COMPONENT $version does not hold exactly one directory."
[[ -f "${tops[0]}/version.php" ]] \
  || fail "The package of $COMPONENT $version has no version.php in its directory."

unpacked=$(sed -n 's/^\$plugin->version *= *\([0-9]\{10\}\);.*/\1/p' "${tops[0]}/version.php")
[[ "$unpacked" == "$version" ]] \
  || fail "The package says version $unpacked, the directory lists it as $version."

rm -rf "$dest"
mkdir -p "$(dirname "$dest")"
mv "${tops[0]}" "$dest"
echo "Unpacked to:       $dest"
