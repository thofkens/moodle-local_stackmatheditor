# One configuration per scope - decision record (#87)

**Decided:** 2026-10-08, build 2026100800. **Status:** in force.

## The invariant

The table `local_stackmatheditor` holds the toolbar configuration per scope:

| Scope | `cmid` | `questionbankentryid` |
|---|---|---|
| global question default | 0 | the entry |
| quiz default | the quiz | `NULL` |
| question in a quiz | the quiz | the entry |

There is exactly one row per scope. Every read returns one row per scope; which one, if there
ever were more, is fixed by the order `timemodified DESC, id DESC`.

## Why not a unique index

A unique index on `(cmid, questionbankentryid)` would not enforce the invariant where it
matters. `questionbankentryid` is `NULL` for the quiz default, and in PostgreSQL and MariaDB
`NULL`s never collide in a unique index - any number of quiz defaults would get through, while
SQL Server would treat them as equal. Moodle advises against unique indexes on nullable columns
for exactly this reason. Making the column `NOT NULL` with a stand-in value (0) would change the
meaning of the column in every query, in backup files already written and in the privacy
export, for a guarantee the application can give as well.

## How the application enforces it

`config_manager::upsert_record()` is the only write path for a scope:

1. a Moodle lock per scope (`scope_<cmid>_<qbeid|default>`): writers of one scope run one
   after the other, on every database and with every lock factory Moodle offers;
2. read, write and the removal of surplus rows in one delegated transaction;
3. after the write, `collapse_scope()` keeps the row a read returns and deletes the others - a
   duplicate from an earlier build, or from a lock factory that does not serialise, is repaired
   by the next write.

Restore inserts through the restore plugin and collapses each restored scope the same way.

Existing data: the upgrade to 2026100800 removes duplicate rows (and rows of deleted activities)
once; `cli/repair_config.php --duplicates` does the same on demand.

## Evidence

- `tests/unit/config_scope_integrity_test.php`: repeated saves keep one row; ties are broken by
  id in single read, batch read and repair alike; a held scope lock makes a second writer fail
  with `locktimeout` instead of writing.
- `tests/concurrency/concurrent_save.php`: several PHP processes write the same two scopes at the
  same moment, many times, against a real site; afterwards there is exactly one row per scope.
  Its control mode runs the same load through the previous release's write path (read, then
  insert; no lock, no transaction) and reports the duplicates it produces - locally on
  PostgreSQL 7 to 11 per run with 8 writers - which shows the load does race. The main CI runs it
  on PostgreSQL and MariaDB (job *Concurrent writes*), the release artefact workflow against the
  site installed from the release ZIP.
- `tests/unit/upgrade_step_test.php` and `tests/upgrade/*`: the upgrade from the published
  build leaves one row per scope.

## Revisit when

the table gets a column that can carry a non-null scope key anyway, or Moodle offers partial or
expression indexes through XMLDB.
