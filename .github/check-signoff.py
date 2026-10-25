#!/usr/bin/env python3
"""Check docs/RELEASE-SIGNOFF.json, the manual sign-off of one build.

    python3 .github/check-signoff.py <plugin dir> [--complete]

Without --complete (main CI): the file is well-formed and belongs to the build in version.php,
every risk it names is described in docs/RESIDUAL-RISKS.md, and every item that is not open
carries a date and a name. Open items are listed, not failed.

With --complete (release-artefact.yml, stable tag): additionally no item may be open. A stable
release waits until every manual check is done or its residual risk is accepted by name.

Exit code 0 when the check holds, 1 otherwise; the last line says PASS or FAIL.
"""

import datetime
import json
import os
import re
import sys

STATUSES = ('open', 'done', 'accepted')
FIELDS = ('id', 'issue', 'risk', 'what', 'allowed', 'status', 'date', 'by', 'note')


def version_php(root):
    """Return (release, build) as version.php declares them."""
    with open(os.path.join(root, 'version.php'), encoding='utf-8') as handle:
        source = handle.read()
    build = re.search(r"\$plugin->version\s*=\s*(\d+)", source)
    release = re.search(r"\$plugin->release\s*=\s*'([^']+)'", source)
    return (release.group(1) if release else None, int(build.group(1)) if build else None)


def check(root, complete):
    """Return the problems and the open items."""
    problems, open_items = [], []
    path = os.path.join(root, 'docs', 'RELEASE-SIGNOFF.json')
    try:
        with open(path, encoding='utf-8') as handle:
            data = json.load(handle)
    except (OSError, ValueError) as error:
        return [f'{path} cannot be read: {error}'], []

    release, build = version_php(root)
    if data.get('release') != release or data.get('build') != build:
        problems.append(f"the sign-off is for {data.get('release')} ({data.get('build')}), "
                        f"version.php declares {release} ({build})")

    with open(os.path.join(root, 'docs', 'RESIDUAL-RISKS.md'), encoding='utf-8') as handle:
        risks = handle.read()

    seen = set()
    for item in data.get('items') or []:
        name = item.get('id', '?')
        missing = [field for field in FIELDS if field not in item]
        if missing:
            problems.append(f"{name}: fields missing: {', '.join(missing)}")
            continue
        if name in seen:
            problems.append(f'{name}: listed twice')
        seen.add(name)
        if item['risk'] and f"| {item['risk']} |" not in risks:
            problems.append(f"{name}: risk {item['risk']} is not described in docs/RESIDUAL-RISKS.md")
        status = item['status']
        if status not in STATUSES:
            problems.append(f'{name}: unknown status "{status}"')
            continue
        if status == 'open':
            open_items.append(f"{name} (#{item['issue']}, {item['risk']}): {item['what']}")
            continue
        if status not in item['allowed']:
            problems.append(f'{name}: "{status}" is not allowed here, only {item["allowed"]}')
        try:
            datetime.date.fromisoformat(item['date'])
        except (TypeError, ValueError):
            problems.append(f'{name}: {status} needs a date YYYY-MM-DD, not "{item["date"]}"')
        if not str(item['by']).strip():
            problems.append(f'{name}: {status} needs the name of who did or accepted it')
    if not seen:
        problems.append('the sign-off lists no item')

    if complete and open_items:
        problems.extend(f'open: {entry}' for entry in open_items)
    return problems, open_items


def main(argv):
    """Run the check and report it."""
    if len(argv) < 2:
        print(__doc__)
        return 2
    complete = '--complete' in argv[2:]
    problems, open_items = check(argv[1], complete)
    for entry in open_items:
        print(f'  open   {entry}')
    for problem in problems:
        print(f'  FAIL   {problem}')
    print('RESULT: ' + ('FAIL' if problems else 'PASS')
          + (' (stable release)' if complete else ' (format; open items are listed, not failed)'))
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
