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
# CI helper: installs Maxima and gnuplot for STACK - and gives up in minutes, not when the job's
# time limit ends it.
#
# Every workflow ran "apt-get update -qq && apt-get install -y -qq maxima gnuplot-nox" inline.
# On 2026-10-07 the upgrade job sat in that step for nineteen minutes without a line of output
# until its limit cancelled it: a package mirror that accepts the connection and then sends
# nothing keeps apt waiting indefinitely, and -qq hides which of the two commands it is. So:
#
#   - every network operation has a timeout, and apt retries a failed download itself;
#   - each phase runs under a hard limit and is tried up to three times;
#   - IPv4 only - a runner without a working IPv6 route is the classic way for apt to hang;
#   - no prompt can wait for an answer nobody gives;
#   - the output says which phase and which attempt is running.
#
# Usage: bash install-maxima.sh        (needs sudo, as on the GitHub runners)
#
# @copyright 2026 Ralf Erlebach
# @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -euo pipefail

PACKAGES="${PACKAGES:-maxima gnuplot-nox}"
MIN_MAXIMA="${MIN_MAXIMA:-5.46}"
PHASE_LIMIT="${PHASE_LIMIT:-150}"
# The download gets more and longer attempts than the other phases: apt keeps what it fetched in
# /var/cache/apt/archives/partial and resumes there, so a slow mirror still makes progress from
# attempt to attempt. On 2026-10-08 the Azure mirror delivered about 17 MB per 150 s; three
# attempts ended 20 MB short of the 54 MB maxima needs, and the job failed.
DOWNLOAD_ATTEMPTS="${DOWNLOAD_ATTEMPTS:-6}"
DOWNLOAD_LIMIT="${DOWNLOAD_LIMIT:-300}"

SUDO=sudo
[[ $(id -u) -eq 0 ]] && SUDO=

APT_OPTIONS=(
  -o Acquire::Retries=3
  -o Acquire::http::Timeout=30
  -o Acquire::https::Timeout=30
  -o Acquire::ForceIPv4=true
  -o DPkg::Lock::Timeout=120
  -o Dpkg::Use-Pty=0
)

# Run one apt phase under a hard limit, up to three times.
# phase NAME ATTEMPTS LIMIT apt-get-arguments...
phase () {
  local name="$1" attempts="$2" limit="$3"; shift 3
  local attempt
  for attempt in $(seq 1 "$attempts"); do
    echo "apt: $name, attempt $attempt of $attempts (limit ${limit}s)"
    # --kill-after: a process that ignores the first signal does not get to wait out the job.
    if $SUDO env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a \
        timeout --kill-after=15 "$limit" apt-get "${APT_OPTIONS[@]}" "$@"; then
      return 0
    fi
    echo "::warning::apt: $name failed or ran into its ${limit}s limit (attempt $attempt)"
    sleep $((attempt * 10))
  done
  return 1
}

# A stale package list is not fatal by itself: the runner image ships one, and the install below
# says clearly if it is not good enough.
phase "update package lists" 3 "$PHASE_LIMIT" update -q \
  || echo "::warning::apt: the package lists could not be updated; trying with the lists on the image"

# Download first, resumable, then install from the local cache - the install phase no longer
# depends on the mirror's speed.
# shellcheck disable=SC2086
phase "download $PACKAGES" "$DOWNLOAD_ATTEMPTS" "$DOWNLOAD_LIMIT" install -y -q --download-only $PACKAGES \
  || { echo "::error::apt: $PACKAGES could not be downloaded after $DOWNLOAD_ATTEMPTS attempts."; exit 1; }
# shellcheck disable=SC2086
phase "install $PACKAGES" 3 "$PHASE_LIMIT" install -y -q --no-download $PACKAGES \
  || { echo "::error::apt: $PACKAGES could not be installed after three attempts."; exit 1; }

version=$(maxima --version 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1)
echo "Installed Maxima ${version:-unknown}"
dpkg --compare-versions "${version:-0}" ge "$MIN_MAXIMA" \
  || { echo "::error::Maxima ${version:-unknown} is older than $MIN_MAXIMA - STACK will reject it."; exit 1; }
