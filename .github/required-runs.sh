#!/usr/bin/env bash
# Check that every workflow a release needs ran on exactly one commit, finished, and is green.
#
#   bash .github/required-runs.sh <owner/repo> <full commit sha> [extra workflow file ...]
#
# release-evidence.yml calls it with the five gates; release-artefact.yml adds
# release-evidence.yml itself, so a tag is checked against the gates directly and not only
# through an evidence run that might have been made before they finished.
#
# Prints one line per requirement and a last line "RESULT: PASS" or "RESULT: FAIL"; the exit
# code says the same (0 / 1). Fail-closed: a workflow that never ran on the commit, that is still
# running, that ended in anything but success, or whose newest run on the commit is not the
# green one, fails the check. Only runs whose head_sha is the commit count - a green run on
# another commit is not looked at.
#
# The Playwright run must contain one green job per Moodle branch of the support matrix (4.5 and
# 5.3, see playwright.yml); a run started for a single branch does not satisfy it.
#
# No state is kept in a pipeline: the result variable is set in this shell, the caller writes
# the output to a file with a plain redirect. (A "{ ... } | tee" block runs in a subshell and
# loses every variable it sets - the false green of release evidence run 37797946872.)

set -u

repo="${1:?owner/repo}"
sha="${2:?commit sha}"
shift 2
failed=0

# Workflows that have to be green on the commit, with the jobs that must be among their green
# jobs (empty: the run conclusion is enough).
declare -A jobs=(
    [moodle-plugin-ci-main.yml]="CI complete"
    [playwright.yml]="Playwright / MOODLE_405_STABLE|Playwright / MOODLE_503_STABLE"
    [a11y-nvda.yml]=""
    [load-k6.yml]=""
    [load-jmeter.yml]=""
)
order=(moodle-plugin-ci-main.yml playwright.yml a11y-nvda.yml load-k6.yml load-jmeter.yml)
for extra in "$@"; do
    jobs[$extra]=""
    order+=("$extra")
done

echo "required runs for $sha"
for wf in "${order[@]}"; do
    # Newest run of this workflow on exactly this commit.
    if ! run=$(gh api "repos/$repo/actions/workflows/$wf/runs?head_sha=$sha&per_page=1" \
            --jq '.workflow_runs[0] // empty | [.id, .status, (.conclusion // "none"), .html_url] | @tsv'); then
        echo "  $wf: FAIL - the API did not answer"
        failed=1
        continue
    fi
    if [ -z "$run" ]; then
        echo "  $wf: FAIL - never ran on this commit"
        failed=1
        continue
    fi
    IFS=$'\t' read -r id status conclusion url <<< "$run"
    if [ "$status" != "completed" ]; then
        echo "  $wf: FAIL - still $status  $url"
        failed=1
        continue
    fi
    if [ "$conclusion" != "success" ]; then
        echo "  $wf: FAIL - $conclusion  $url"
        failed=1
        continue
    fi

    needed="${jobs[$wf]}"
    if [ -n "$needed" ]; then
        green=$(gh api "repos/$repo/actions/runs/$id/jobs?per_page=100" \
            --jq '.jobs[] | select(.conclusion == "success") | .name') || green=""
        missingjobs=()
        IFS='|' read -r -a wanted <<< "$needed"
        for job in "${wanted[@]}"; do
            if ! grep -qxF "$job" <<< "$green"; then
                missingjobs+=("$job")
            fi
        done
        if [ "${#missingjobs[@]}" -ne 0 ]; then
            echo "  $wf: FAIL - green run without the job(s): $(printf '%s; ' "${missingjobs[@]}")$url"
            failed=1
            continue
        fi
    fi
    echo "  $wf: success  $url"
done

if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS"
    exit 0
fi
echo "RESULT: FAIL"
exit 1
