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
# CI helper: takes a freshly built site through its first login before a load test starts.
#
# The load plans start ten students within five seconds on a site nobody has ever logged in to.
# In the JMeter run of 2026-10-07 the second login arrived while the very first one was still
# being processed - 783 ms, against 40 ms for every later one - and was answered with a Moodle
# error page (HTTP 404); the eight after it were fine. A load test is meant to measure the page
# under load, not what a site does once in its life. So one user who is not part of the plan logs
# in first, alone, and opens the pages the plan uses.
#
# Usage: warm-up-site.sh <base url> <username> <password> [course module id of the quiz]
#
# @copyright 2026 Ralf Erlebach
# @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -euo pipefail

base="${1:?base url}"
user="${2:?username}"
pass="${3:?password}"
cmid="${4:-}"

jar=$(mktemp)
page=$(mktemp)
trap 'rm -f "$jar" "$page"' EXIT

fetch () { curl -sS --location --max-time 120 --cookie "$jar" --cookie-jar "$jar" "$@"; }

fetch -o "$page" "$base/login/index.php"
token=$(grep -oE 'name="logintoken" value="[^"]+"' "$page" | head -1 | sed 's/.*value="//; s/"$//')
[[ -n "$token" ]] || { echo "::error::Warm-up: the login page has no login token."; exit 1; }

landed=$(fetch -o "$page" -w '%{http_code} %{url_effective}' \
  --data-urlencode "username=$user" --data-urlencode "password=$pass" \
  --data-urlencode "logintoken=$token" "$base/login/index.php")
case "$landed" in
  "200 "*"/login/index.php"*) echo "::error::Warm-up: $user could not log in ($landed)."; exit 1 ;;
  "200 "*) echo "Warm-up: $user logged in, landed on ${landed#200 }" ;;
  *) echo "::error::Warm-up: the first login was answered with $landed."; exit 1 ;;
esac

for path in /my/ ${cmid:+/mod/quiz/view.php?id=$cmid}; do
  code=$(fetch -o /dev/null -w '%{http_code}' "$base$path")
  echo "Warm-up: $path -> HTTP $code"
  [[ "$code" == "200" ]] || { echo "::error::Warm-up: $path answered HTTP $code."; exit 1; }
done
