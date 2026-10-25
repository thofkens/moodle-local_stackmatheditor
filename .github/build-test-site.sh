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
# CI helper: builds a disposable, fully installed Moodle site with qtype_stack and
# local_stackmatheditor for the Playwright, k6 and JMeter workflows.
#
# Run from the workspace root; the plugin checkout must be in ./plugin. The site ends up in
# ./moodle and is installed via admin/cli/install.php (a real site, not a test site), which is
# why ALL declared STACK dependencies must be present - install.php enforces them, whereas the
# PHPUnit/Behat initialisation of moodle-plugin-ci does not.
#
# Environment (all optional):
#   MOODLE_BRANCH (MOODLE_405_STABLE), MOODLE_WWWROOT (http://127.0.0.1:8000),
#   MOODLE_DATAROOT (/tmp/moodledata), DB_TYPE (pgsql; or mariadb), DB_PORT (5432 / 3306),
#   DB_NAME/DB_USER/DB_PASS (moodle/moodle/moodle),
#   MOODLE_ADMIN_PASS (Admin!23), STACK_BRANCH (master), ADAPTIVEMULTIPART_BRANCH (master),
#   DFEXPLICITVAILDATE_BRANCH (master), DFCBMEXPLICITVAILDATE_BRANCH (master),
#   IMPORTASVERSION_BRANCH (main).
#
# @copyright 2026 Ralf Erlebach
# @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -euo pipefail

MOODLE_BRANCH="${MOODLE_BRANCH:-MOODLE_405_STABLE}"
MOODLE_WWWROOT="${MOODLE_WWWROOT:-http://127.0.0.1:8000}"
MOODLE_DATAROOT="${MOODLE_DATAROOT:-/tmp/moodledata}"
MOODLE_ADMIN_PASS="${MOODLE_ADMIN_PASS:-Admin!23}"
DB_TYPE="${DB_TYPE:-pgsql}"
if [[ "$DB_TYPE" == "pgsql" ]]; then
  DB_PORT="${DB_PORT:-5432}"
else
  DB_PORT="${DB_PORT:-3306}"
fi
DB_NAME="${DB_NAME:-moodle}"
DB_USER="${DB_USER:-moodle}"
DB_PASS="${DB_PASS:-moodle}"

# Clone a repository without its .git directory into a Moodle plugin directory.
clone_plugin () {
  local repo="$1" ref="$2" dest="$3"
  echo "  $repo@$ref -> $dest"
  rm -rf "$dest"
  git clone --quiet --depth 1 --branch "$ref" "https://github.com/$repo.git" "$dest"
  rm -rf "$dest/.git"
}

echo "Moodle $MOODLE_BRANCH"
git clone --quiet --depth 1 --branch "$MOODLE_BRANCH" https://github.com/moodle/moodle.git moodle

# Moodle 5.1+ keeps the web root (and thus the plugin directories) in public/.
web=moodle
if [[ -d moodle/public ]]; then
  web=moodle/public
  # Moodle 5.1+ no longer ships its third-party PHP libraries; without them the routed API
  # (r.php, e.g. saving a user preference such as the colour mode) fails with a missing class.
  echo "Composer dependencies"
  composer install --working-dir=moodle --no-dev --no-interaction --no-progress --quiet
fi

echo "Plugins:"
# The plugin under test comes from this checkout, never from a clone.
rm -rf "$web/local/stackmatheditor"
cp -a plugin "$web/local/stackmatheditor"
rm -rf "$web/local/stackmatheditor/.git"
clone_plugin maths/moodle-qtype_stack "${STACK_BRANCH:-master}" "$web/question/type/stack"
clone_plugin maths/moodle-qbehaviour_adaptivemultipart "${ADAPTIVEMULTIPART_BRANCH:-master}" \
  "$web/question/behaviour/adaptivemultipart"
clone_plugin maths/moodle-qbehaviour_dfexplicitvaildate "${DFEXPLICITVAILDATE_BRANCH:-master}" \
  "$web/question/behaviour/dfexplicitvaildate"
clone_plugin maths/moodle-qbehaviour_dfcbmexplicitvaildate "${DFCBMEXPLICITVAILDATE_BRANCH:-master}" \
  "$web/question/behaviour/dfcbmexplicitvaildate"
clone_plugin maths/moodle-qbank_importasversion "${IMPORTASVERSION_BRANCH:-main}" \
  "$web/question/bank/importasversion"

# A plugin root must contain exactly one version.php. More than one means a repository was
# copied into an existing plugin directory, and the tested site would not be reproducible.
count=$(find "$web/local/stackmatheditor" -name version.php | wc -l)
if [[ "$count" -ne 1 ]]; then
  echo "local/stackmatheditor contains $count version.php files (expected exactly 1)."
  exit 1
fi

# install.php writes config.php itself and aborts when one exists. It needs an existing,
# writable dataroot OUTSIDE the Moodle tree.
mkdir -p "$MOODLE_DATAROOT"
php moodle/admin/cli/install.php \
  --non-interactive --agree-license \
  --dbtype="$DB_TYPE" --dbhost=127.0.0.1 --dbport="$DB_PORT" --dbname="$DB_NAME" \
  --dbuser="$DB_USER" --dbpass="$DB_PASS" \
  --wwwroot="$MOODLE_WWWROOT" --dataroot="$MOODLE_DATAROOT" \
  --fullname="STACK MathQuill Editor CI" --shortname="SMECI" \
  --adminpass="$MOODLE_ADMIN_PASS" --adminemail=ci@example.invalid

echo "Site installed: $MOODLE_WWWROOT (web root: $web)"
