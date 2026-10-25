# Entwicklungsumgebung — local_stackmatheditor

Einrichtung der lokalen Entwicklungsumgebung, die Qualitätsgates, die CI-Pipelines und die
Session-Prompts.

Zielumgebung: Moodle 4.5 unter WSL/Ubuntu 24.04, erreichbar als
`http://localhost/moodle45_aliseadele`. Unterstützt werden Moodle 4.5 bis 5.2.

---

## 1. Warum diese Einrichtung aufwendiger ist als bei anderen Plugins

Drei Dinge unterscheiden dieses Plugin von einem gewöhnlichen `local_`-Plugin, und alle drei
schlagen auf die Umgebung durch:

**STACK braucht Maxima.** `qtype_stack` ist eine harte Abhängigkeit. STACK installiert zwar auch
ohne funktionierende Maxima-Verbindung, bewertet dann aber nichts. Genau die interessanten
Behat-Szenarien (tex2max über das echte CAS, Pre-Fill nach dem Speichern) sind ohne Maxima
wertlos — sie überspringen sich oder scheitern an einer Stelle, die nichts mit dem Plugin zu tun
hat.

**STACK bringt eigene Abhängigkeiten mit.** Seit STACK 4.13 verlangt `qtype_stack` neben
`qbehaviour_adaptivemultipart` auch `qbehaviour_dfexplicitvaildate`,
`qbehaviour_dfcbmexplicitvaildate` und `qbank_importasversion`. Fehlt eines davon, bricht eine
echte Installation mit „Dependencies check failed for qtype_stack" ab, bevor
`local_stackmatheditor` überhaupt erreicht wird — ein Fehlerbild, das leicht dem falschen Plugin
zugeschrieben wird. Die PHPUnit-/Behat-Initialisierung von moodle-plugin-ci prüft das nicht; es
fällt erst bei einer echten Installation auf (Playwright, k6, JMeter, lokale Site).

**Die Laufzeit ist eine Kette.** Hook → Injector → AMD-Bootstrap (`mathquill_init`) →
MathQuill → `tex2max`/`max2tex` → verstecktes STACK-Eingabefeld → Submit → STACK/Maxima. Jedes
Glied kann still versagen. Deshalb gibt es neben PHPUnit und Behat auch Jest (reine
Konvertierungslogik) und Playwright: ob ein Asset-Pfad stimmt, sieht man nicht im DOM, sondern
nur im Netzwerk.

---

## 2. Voraussetzungen

| Werkzeug | Version | Wofür |
|---|---|---|
| PHP | 8.2–8.4 | 8.2 ist das CI-Minimum; 8.4 erst ab Moodle 5.1 in der Matrix |
| PostgreSQL oder MariaDB | 16 / 10.11 | Entwicklungs-, PHPUnit- und Behat-Datenbank |
| Node.js | 22 (`lts/jod`, Moodle-4.5-`.nvmrc`) | Grunt, ESLint, Jest, Playwright |
| Composer | 2.x | moodle-plugin-ci |
| Maxima + gnuplot | Maxima ≥ 5.46 | STACK-Auswertung |
| Java (JRE 8+) | — | nur für JMeter |
| k6 | aktuell | nur für die Lasttests |

**Maxima ≥ 5.46.** Ubuntu 22.04 liefert 5.45.1, das STACK ablehnt; Ubuntu 24.04 liefert 5.46.0.
Deshalb laufen auch alle CI-Jobs, die ein Testsite initialisieren, auf `ubuntu-24.04`.

`moodle-plugin-ci` in **Version 4** — eine ältere lokale `moodle-cs` akzeptiert, was die CI
ablehnt, und produziert dann ein „lokal grün", das nichts bedeutet.

---

## 3. Moodle-Baum und Plugins

```bash
cd /var/www/html
git clone --branch MOODLE_405_STABLE --depth 1 https://github.com/moodle/moodle.git moodle45_aliseadele
cd moodle45_aliseadele
```

Abhängigkeiten in der Reihenfolge, in der Moodle sie braucht:

```bash
git clone --branch master https://github.com/maths/moodle-qbehaviour_adaptivemultipart.git \
    question/behaviour/adaptivemultipart
git clone --branch master https://github.com/maths/moodle-qbehaviour_dfexplicitvaildate.git \
    question/behaviour/dfexplicitvaildate
git clone --branch master https://github.com/maths/moodle-qbehaviour_dfcbmexplicitvaildate.git \
    question/behaviour/dfcbmexplicitvaildate
git clone --branch main   https://github.com/maths/moodle-qbank_importasversion.git \
    question/bank/importasversion
git clone --branch master https://github.com/maths/moodle-qtype_stack.git \
    question/type/stack

# Optional: für Tests im adaptiven Kontext (page type mod-adaptivequiz-view).
# git clone https://github.com/vtos/moodle-mod_adaptivequiz.git mod/adaptivequiz

git clone --branch development https://github.com/ralferlebach/moodle-local_stackmatheditor.git \
    local/stackmatheditor
```

Anschließend Maxima nach STACK-Anleitung einrichten und unter
*Website-Administration → Plugins → Fragetypen → STACK → Gesundheitscheck* verifizieren. Ohne
grünen Healthcheck ist jeder STACK-Testfall wertlos.

---

## 4. `config.php` für die Entwicklung

```php
$CFG->wwwroot   = 'http://localhost/moodle45_aliseadele';
$CFG->dataroot  = '/var/moodledata/moodle45_aliseadele';

// PHPUnit — eigene Tabellenpräfixe und eigenes Dataroot, sonst löscht die Initialisierung
// die Entwicklungsdaten.
$CFG->phpunit_prefix   = 'phpu_';
$CFG->phpunit_dataroot = '/var/moodledata/phpu_stackmatheditor';

// Behat.
$CFG->behat_prefix     = 'bht_';
$CFG->behat_dataroot   = '/var/moodledata/bht_stackmatheditor';
$CFG->behat_wwwroot    = 'http://127.0.0.1:8000';

// Entwicklung.
$CFG->debug        = (E_ALL | E_STRICT);
$CFG->debugdisplay = 1;
$CFG->cachejs      = false;
```

`behat_wwwroot` bewusst auf `127.0.0.1`: `localhost` kann auf `::1` auflösen, wo PHPs
eingebauter Server nicht lauscht — der Client meldet dann HTTP 0, was wie ein Timeout aussieht.

```bash
php admin/cli/install_database.php --agree-license --adminpass='...' --adminemail='...'
php admin/tool/phpunit/cli/init.php
php admin/tool/behat/cli/init.php
make -C local/stackmatheditor behat-stack     # STACK-CAS im Behat-Site vorbereiten
```

---

## 5. Werkzeuge installieren

```bash
sudo apt-get install -y maxima gnuplot-nox
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ~/ci ^4
export PATH="$HOME/ci/bin:$HOME/ci/vendor/bin:$PATH"

cd /var/www/html/moodle45_aliseadele && npm install     # Grunt + ESLint aus dem Moodle-Baum

git clone --depth 1 https://github.com/moodlehq/moodle-local_moodlecheck.git local/moodlecheck
```

`local_moodlecheck` wird nur für `make lint-phpdoc` gebraucht und darf auf einem produktiven
System nicht liegen bleiben.

Läuft `composer` als root, deaktiviert es seine Plugins — dann fehlt der Coding-Standard
`moodle` („the moodle coding standard is not installed"). Abhilfe:
`~/ci/vendor/bin/phpcs --config-set installed_paths ~/ci/vendor/moodlehq/moodle-cs,~/ci/vendor/phpcsstandards/phpcsutils,~/ci/vendor/phpcsstandards/phpcsextra`.

---

## 6. Qualitätsgates und Tests

Alles aus `local/stackmatheditor/`:

| Befehl | Was passiert |
|---|---|
| `make check` | PHPCS, PHPDoc, Mustache, Gherkin, PHPCPD, ESLint, AMD-Build, Jest, PHPUnit |
| `make fix` | PHPDoc- und Style-Autofix, danach AMD-Rebuild und ESLint |
| `make lint-php` | nur PHPCS, Moodle-Standard |
| `make lint-js` | ESLint über `amd/src/` |
| `make amd` | AMD-Build (Ergebnis committen!) |
| `make jest` | Jest-Tests der Konvertierungsmodule (mit den echten Server-Definitionen) |
| `make phpunit` | PHPUnit-Testsuite dieses Plugins |
| `make behat-stack` / `make behat` | STACK-CAS im Behat-Site vorbereiten / Behat-Szenarien |
| `make playwright SME_ADMIN_PASS=…` | Browser-Smoke gegen die laufende Instanz |
| `make k6` / `make jmeter` | Last-Smoke (BASE_URL = `$CFG->wwwroot`) |

**Immer im Moodle-Baum prüfen.** `moodle.Files.LangFilesOrdering`,
`moodle.PHPUnit.TestCaseNames` und `moodle.PHPUnit.TestCaseCovers` schweigen, wenn ein
alleinstehendes Verzeichnis geprüft wird.

**Nach jeder JS-Änderung `make amd`, und das Ergebnis committen.** Die CI baut auf
`MOODLE_405_STABLE` neu und schlägt bei jeder abweichenden Datei in `amd/build/` fehl. Ein
anderer Moodle-Zweig kann anders minifizieren; deshalb läuft der Grunt-Gate nur auf 4.5.

### Testarten und was sie jeweils können

| Art | Ort | Deckt ab | Deckt bewusst nicht ab |
|---|---|---|---|
| PHPUnit | `tests/unit/` | definitions, config_manager, quiz_helper, page_helper | Alles, was einen Browser braucht |
| Behat | `tests/behat/` | Editor im Quizversuch, tex2max über das echte CAS, Toolbar-Konfiguration, Pre-Fill | Last, Asset-Auslieferung im Detail |
| Jest | `tests/jest/` | `tex2max.js` / `max2tex.js` ohne Moodle, mit den echten Definitionen (`fixtures/definitions.json`) — schnellster Ort für Roundtrip-Tabellen | DOM, MathQuill, STACK |
| Playwright | `tests/playwright/` | Einstellungsseite, ausgeliefertes `amd/build` über `requirejs.php`, Videos + Traces | Fachliche Bewertung durch STACK |
| k6 / JMeter | `tests/load/` | Latenz und Fehlerrate der Lese-Pfade unter Parallelität | Funktionale Korrektheit |

Die Aufteilung ist keine Geschmacksfrage. Die Konvertierungsregeln (#30, #34, #35, #39, #42)
brauchen dutzende Ein-/Ausgabepaare; in Behat kostet jedes Paar einen Seitenaufbau samt CAS,
in Jest eine Millisekunde. Umgekehrt beweist Jest nichts darüber, ob MathQuill den Ausdruck
überhaupt so liefert (`\land` wird intern zu `\wedge`) — das bleibt Behat vorbehalten.

### Jest-Definitionen

Die Jest-Tests laufen mit `tests/jest/fixtures/definitions.json`, einem Export von
`definitions::export_for_js()` — also mit denselben Funktionsnamen, Einheiten und reservierten
Wörtern wie im Browser. `tests/unit/jest_fixture_test.php` schlägt fehl, sobald Fixture und
PHP-Definitionen auseinanderlaufen. Nach einer gewollten Änderung an `classes/definitions.php`:

```bash
php local/stackmatheditor/tests/jest/export_definitions.php     # aus dem Moodle-Root
```

### Coverage

`tests/coverage.php` beschränkt die Messung auf `classes/`. Gemessen am 10.09.2026: **42,41 %**
(545/1285, Moodle 4.5, PHP 8.3, pcov). Die Untergrenze in `moodle-plugin-ci-main.yml` steht auf
40 % und wird als Ratsche geführt: anheben, sobald neue Tests die Zahl heben; nie still senken.
Größte Lücken: `form/configure_form.php`, die drei Injector-Klassen, `external/get_config.php`,
`hook_callbacks.php`.

---

## 7. CI

| Workflow | Auslöser | Zweck |
|---|---|---|
| `moodle-plugin-ci-dev.yml` | Push/PR auf jeden Branch außer `main` (also `development`) | Schnelles paralleles Feedback |
| `moodle-plugin-ci-main.yml` | Push/PR auf `main` | Volle Matrix 4.5–5.2 × PHP × DB + Release-Gates |
| `playwright.yml` | **nur manuell** | Browser-Smoke mit Videos |
| `load-k6.yml`, `load-jmeter.yml` | **nur manuell** | Last-Smoke |

**Aufbau der Dev-Pipeline.** Parallel starten `lint-php`, `codeanalysis`, `quality`
(codechecker, phpdoc, savepoints, validate, mustache, gherkinlint), die
**JavaScript-/CSS-Abteilung** `javascript` (ESLint + AMD-Build-Frische, stylelint, Jest) und
`stale-files`. `phpunit` wartet auf `lint-php`; `behat` wartet auf `lint-php` **und**
`javascript` — ein kaputter oder veralteter AMD-Build macht jedes Browser-Szenario wertlos, also
läuft Behat erst, wenn das Frontend grün ist.

`moodle-plugin-ci-main.yml` ist der maßgebliche Release-Gate: Matrix, Jest, Release-Artefakt
(`git archive` darf keine Entwicklerwerkzeuge enthalten), Coverage-Untergrenze und veraltete
Dateien. Der Job `ci-complete` ist der Status-Check für den Branch-Schutz.

**Manuelle Workflows** (Playwright, k6, JMeter) laufen nie bei Push, PR oder Merge. Der Button
„Run workflow" erscheint im Actions-UI erst, wenn die Datei auf dem Default-Branch (`main`) liegt;
der zu testende Branch wird dann im Dialog unter „Use workflow from" gewählt (z. B.
`development`). Alternativ per CLI: `gh workflow run playwright.yml --ref development`.

**STACK-Branches** sind in jedem Workflow als Variablen geführt (`STACK_BRANCH`,
`ADAPTIVEMULTIPART_BRANCH`, `DFEXPLICITVAILDATE_BRANCH`, `DFCBMEXPLICITVAILDATE_BRANCH`,
`IMPORTASVERSION_BRANCH`). Bricht ein STACK-Release die Pipeline, wird dort auf einen Tag
gepinnt.

**Echte Test-Sites** für Playwright, k6 und JMeter baut `.github/build-test-site.sh`
(Moodle + alle STACK-Abhängigkeiten + Plugin, `admin/cli/install.php`).
`.github/serve-test-site.sh` startet den PHP-Server mit mehreren Workern und wartet auf HTTP 200 —
es muss im selben Schritt wie die Tests *gesourct* werden, weil ein Server aus einem früheren
Schritt nicht zuverlässig weiterlebt.

### Laufzeiten und Höchstlaufzeiten

Gemessen auf GitHub am 11.09.2026 (Commit `e55ea52`, `main`). Die `timeout-minutes` der Jobs und
die Schwellen der Tests sind daraus mit Puffer abgeleitet; ein Lauf, der sie überschreitet, ist
rot. Werte mit „(vorläufig)" stammen aus lokalen Referenzläufen und werden nach dem ersten
GitHub-Lauf der neuen Suiten nachgeschärft.

| Workflow / Job | gemessen | Höchstlaufzeit |
|---|---|---|
| Main: `CI / <Moodle> / <PHP> / <DB>` (8 Zellen) | 14,1–20,1 min (davon Behat 9,8–15,3 min) | 35 min |
| Main: Coverage | 3,4 min | 12 min |
| Main: Jest / Release-Artefakt / Stale files / Gates | je < 0,5 min | je 5 min |
| Main gesamt | 20,5 min | – |
| Dev gesamt | 14,3–17,3 min | Jobs: PHP/Codeanalyse 10, Quality/JS-CSS 15, PHPUnit 20, Behat 30, Rest 5 min |
| Playwright (nur Smoke) | 2,7–4,5 min | 20 min (inkl. Matrix, Performance, a11y) |
| k6 (nur Smoke) | 2,8–3,2 min | 15 min (inkl. Attempt-Last) |
| JMeter (nur Smoke) | 2,8–3,2 min | 15 min (inkl. Attempt-Last) |
| k6 mit Attempt-Last (60 s) | 5,6 min | 15 min |
| JMeter mit Attempt-Last (60 s) | 5,5 min | 15 min |

| Test | gemessen | Grenze |
|---|---|---|
| Playwright Smoke (je Test) | 1,3 s / 4,2 s / 0,02 s | 20 s |
| Playwright Einstellungsmatrix (je Test) | lokal 9–40 s | 120 s |
| Playwright Performance: 10 Editoren bereit | lokal 1,2–2,3 s | 6 s (vorläufig) |
| Playwright a11y | lokal 30 s | 90 s |
| k6 Smoke `http_req_duration` | p95 42–95 ms, max 125–359 ms | p95 < 500 ms, max < 3 s |
| k6 Attempt: Versuchsseite (10 STACK-Fragen) | p95 221 ms, max 452 ms | p95 < 3 s, max < 8 s |
| k6 Attempt: `get_config` | p95 52 ms, max 144 ms | p95 < 0,8 s, max < 3 s |
| k6 Attempt: Anmeldung + Versuchsstart (einmal je VU) | max 43 s beim gleichzeitigen Start der 10 VUs | keine eigene Schwelle (`http_req_duration` bewertet nur die Schleife) |
| JMeter Smoke: Login-Seite / AMD-Modul | max 83 ms / 5 ms | 3000 ms (kalter erster Aufruf bis ~2 s) / 500 ms |
| JMeter Attempt: Seite / `get_config` / Start | max 243 ms / 50 ms / 743 ms | 8000 / 3000 / 20000 ms |

### Artefakte

Jeder Job lädt ein `error-summary-*`-ZIP mit allen Logs hoch. Behat legt `behat_dump` und
`behat_faildump` hinein. Playwright lädt Report, Videos, Traces und das Serverlog in **beiden**
Ausgängen hoch (`playwright-success-…` bzw. `playwright-failure-…`). JMeter liefert
`results.jtl` und das HTML-Dashboard, k6 die `k6-summary.json`.

---

## 8. Session-Prompt: Start

```text
# Session start — local_stackmatheditor

You are continuing development of local_stackmatheditor, a Moodle local plugin that replaces
STACK algebraic inputs in mod_quiz and mod_adaptivequiz with a MathQuill visual formula editor
and converts between LaTeX and Maxima (tex2max / max2tex).

Before changing code:

1. Read docs/ENTWICKLUNGSUMGEBUNG.md and the latest docs/sessions/session-*.md.
   One conversation is one session: open docs/sessions/session-NNN.md at the start and append
   to it as you go, rather than writing it up at the end.
2. Read the open GitHub issues you are working on in full. Their acceptance criteria are the
   specification; do not work from a summary of them.
3. Inspect the actual code before using it: definitions, config_manager, the injectors and the
   AMD modules already exist and are more complete than they look.
4. Conversion rules live in amd/src/tex2max.js and amd/src/max2tex.js only; set-theory and
   logic operators come from the central table amd/src/operator_map.js, whose Maxima side must
   be valid in STACK's security map (set operations are functions there: union, intersection,
   setdifference, elementp, subsetp). Logic buttons write and/or (STACK judges the statement as
   a whole); nounand/nounor are reserved for structures whose parts STACK assesses one by one:
   equation systems (SYSTEM_JOIN) and ± solution sets (SOLUTION_JOIN). Every rule change gets Jest
   cases (tests/jest) and, where MathQuill's own normalisation matters, a Behat scenario.
5. Toolbar entries are defined server-side (classes/definitions.php) and exported to JS; the
   client does not invent operators the server does not know.
6. The original STACK input stays in the DOM, positioned off-screen — never display:none
   (focusability, accessibility tree, STACK validation).
7. Every CAS-dependent Behat scenario resets the platform through the explicit step
   "the STACK CAS platform is reset to direct Maxima". Feature files use English UI strings.
8. make check is a fast local pre-check; GitHub CI is the authoritative release gate. Run the
   checks inside a Moodle tree — several sniffs stay silent on a standalone directory.

Current session objective:
> [One concrete slice. Name the issue number.]
```

---

## 9. Session-Prompt: Ende

```text
# Session end — local_stackmatheditor

Before finishing:

1. One conversation is one session. docs/sessions/session-NNN.md is written during the session.
   Never overwrite an earlier session's file.
2. Record changed files, conversion rules touched (with input/output examples), tests written or
   run, decisions taken and their reasons, and unresolved risks.
3. Verify every conversion rule change has Jest cases and, where needed, a Behat scenario.
4. Run make check plus the relevant PHPUnit and Behat tests. Record skips honestly — a skipped
   STACK test is not a passing STACK test.
5. Rebuild AMD (make amd) and commit amd/build. A stale build ships old conversion logic while
   amd/src looks perfectly current.
6. Bump version.php (YYYYMMDDNN, today's date; release as three-part number, e.g. 1.2.0).
7. Build and inspect the COMPLETE plugin ZIP (local/stackmatheditor/, all files, no
   node_modules or generated reports). Anything deleted also goes into db/removed_files.txt.
```


---

## 10. Häufige Stolpersteine

| Symptom | Ursache |
|---|---|
| „Dependencies check failed for qtype_stack" | Eine der vier STACK-Abhängigkeiten fehlt (Abschnitt 3) |
| STACK-Test „passed", ohne etwas zu prüfen | Maxima fehlt oder CAS nicht initialisiert — `make behat-stack` |
| Behat: CAS-Szenario scheitert trotz grünem Healthcheck | Plattform im Behat-DB ≠ `linux`; der `@BeforeScenario`-Hook allein reicht nicht, der explizite Schritt ist Pflicht |
| JS-Änderung wirkt nicht | `amd/build/` nicht neu gebaut, oder `$CFG->cachejs` nicht auf `false` |
| Grunt in der CI: „File is stale" | `make amd` nicht ausgeführt oder mit anderem Node/Moodle-Zweig gebaut |
| PHPCS lokal grün, CI rot | Außerhalb des Moodle-Baums geprüft, oder ältere `moodle-cs` |
| Behat meldet HTTP 0 | `behat_wwwroot` auf `localhost` statt `127.0.0.1` |
| Behat-Schritt findet deutschen Text nicht | Feature-Dateien müssen englische UI-Strings verwenden |
| JMeter-Job grün, obwohl alles 404 war | JMeter endet immer mit 0 — nur `check_jtl.py` entscheidet |
| Stale-files-Job rot | Datei aus `db/removed_files.txt` liegt noch im Repo — `git rm` |
| Testseite: STACK-Fragen zeigen „unexpected internal error … marked as broken during editing or import", kein Editor | Beim Import validiert STACK jede Frage mit dem CAS; auf einer frisch installierten Seite ist `maximacommand` leer. `seed.php` richtet den CAS jetzt vor dem Import ein und bricht ab, falls eine Frage als defekt markiert wird |
| Last-Test: „POST start attempt" scheitert oder dauert > 100 s | Kalter CAS: alle simulierten Studierenden starten gleichzeitig, jeder Start instanziiert zehn STACK-Fragen mit frischem Maxima. `seed.php` wärmt den CAS-Ergebnis-Cache vorher an (lokal gemessen: kalt ~108 s je Start, vorgewärmt 0,5–2,6 s) |
| Playwright/Last-Workflow: „Environment variable SME_… is not set" | Variablen aus `seed.php` erreichen `$GITHUB_ENV` nur, wenn der `sed`-Filter Ziffern im Namen zulässt (`[A-Z_][A-Z0-9_]*`) |

---

## 11. Warum die Prüfungen im Moodle-Baum laufen müssen

**Zum `<plugin>`-Argument.** `moodle-plugin-ci` legt beim `install` eine eigene `.env`-Datei
neben seiner Binärdatei an und lädt sie bei jedem Aufruf. Darin stehen `MOODLE_DIR`,
`PLUGIN_DIR` und `MOODLE_START_BEHAT_SERVERS=YES`. Daraus folgt:

* **Nach einem `install`: gar kein Argument.** Die Vorgabe zeigt auf die *installierte* Kopie im
  Moodle-Baum — nur dort feuern die baumabhängigen Sniffs.
* **Ohne `install`** (etwa `phplint`, `phpmd`, `phpcpd` im Schnellstart) ist das Argument
  Pflicht; ohne es bricht der Befehl mit „Not enough arguments" ab, bevor er eine einzige Datei
  geprüft hat.
* **`--start-servers` ist überflüssig**: `MOODLE_START_BEHAT_SERVERS=YES` startet Selenium und
  den PHP-Server bei jedem `behat`-Aufruf automatisch.

Diese `.env` ist **nicht** `$GITHUB_ENV`. `"$PLUGIN_DIR"` in einer Workflow-Zeile ist leer.

**Ausschlüsse** liest `moodle-plugin-ci` aus `.moodle-plugin-ci.yml` (`filter.notPaths`) und aus
`thirdpartylibs.xml` — **nicht** aus `phpcs.xml`. Diese Datei dient nur IDEs und einem nackten
`phpcs`-Aufruf.

**Kein `package.json` im Plugin-Root.** `moodle-plugin-ci install` führt sonst zusätzlich
`npm install` im Plugin aus. Jest und Playwright haben deshalb eigene Pakete unter `tests/`.

### Der Ablauf, der tatsächlich etwas beweist

```bash
cd ~/ci-run
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
export PATH="$PWD/ci/bin:$PWD/ci/vendor/bin:$PATH"
git clone --branch development https://github.com/ralferlebach/moodle-local_stackmatheditor.git plugin

moodle-plugin-ci add-plugin --branch master maths/moodle-qtype_stack
moodle-plugin-ci add-plugin --branch master maths/moodle-qbehaviour_adaptivemultipart
moodle-plugin-ci add-plugin --branch master maths/moodle-qbehaviour_dfexplicitvaildate
moodle-plugin-ci add-plugin --branch master maths/moodle-qbehaviour_dfcbmexplicitvaildate
moodle-plugin-ci add-plugin --branch main   maths/moodle-qbank_importasversion
moodle-plugin-ci install --plugin ./plugin --db-type pgsql --db-host 127.0.0.1 \
    --db-user moodle --db-pass moodle --branch MOODLE_405_STABLE

moodle-plugin-ci phplint
moodle-plugin-ci codechecker --max-warnings 0 \
    --exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment
moodle-plugin-ci phpdoc --max-warnings 0
moodle-plugin-ci validate
moodle-plugin-ci savepoints
moodle-plugin-ci mustache
moodle-plugin-ci grunt --max-lint-warnings 0
moodle-plugin-ci phpunit --fail-on-warning --testdox
```

Genau diese Folge lief am 10.09.2026 lokal grün (Moodle 4.5.13+, PHP 8.3, PostgreSQL 16,
Maxima 5.46.0, moodle-plugin-ci 4.5.11, STACK 4.13.1): 57 PHPUnit-Tests, `amd/build`
byte-identisch nach dem Neubau.

### Zeilenenden

`* text=auto eol=lf` in `.gitattributes` ist kein Kosmetikeintrag. Unter CRLF meldet PHPCS
`Generic.Files.LineEndings` und „Boilerplate comment wrong line" in praktisch jeder Datei, weil
der Sniff Kopfzeilen zählt. Ohne die Regel bringt der nächste Windows-Checkout die CRLF zurück,
und die CI wird rot aus einem Grund, den man im Diff nicht sieht. Die Patch-ZIPs dieses Projekts
werden deshalb immer mit LF ausgeliefert.

---

## 12. Auslieferung

Jede Iteration wird als **vollständige Plugin-ZIP** ausgeliefert (`local/stackmatheditor/`, alle
Dateien, ohne `node_modules` und lokal erzeugte Reports). Das Plugin-Verzeichnis wird damit
komplett ersetzt, statt nur überschrieben:

```bash
cd /var/www/html/moodle45_aliseadele/local
rm -rf stackmatheditor.old && mv stackmatheditor stackmatheditor.old
unzip -q ~/Downloads/sme_v1.2.0_NN.zip -d ..      # enthält local/stackmatheditor/
cp -a stackmatheditor.old/.git stackmatheditor/    # Git-Historie behalten
cd stackmatheditor && git status                   # gelöschte Dateien erscheinen als "deleted"
```

Release-Name dreistellig (`1.2.0`), Versionsnummer nach Datum und je Iteration hochgezählt
(`2026091100`, `2026091101`, …).
