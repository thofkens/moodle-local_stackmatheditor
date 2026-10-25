# Pflicht- und Lastenheft: local_stackmatheditor

**Stand:** 2026-05-27  
**Plugin:** `local_stackmatheditor` (Moodle Local Plugin)  
**Branch:** `experimental-equation-systems` → Ziel: `develop`  
**Version:** 1.1 (2026052701)  

---

## 1. Projektziel

Bereitstellung eines visuellen, MathQuill-basierten Formeleditors für
STACK-Fragebogen-Eingaben innerhalb von Moodle-Quiz-Versuchen, Bewertungsseiten
und STACK-Fragenvorschauen. Der Editor wandelt LaTeX-Notation automatisch in
Maxima-kompatible Ausdrücke um und erlaubt eine detaillierte Konfiguration pro
Instanz, Test und Frage.

---

## 2. Lastenheft (Anforderungen des Auftraggebers)

### 2.1 Funktionale Anforderungen

#### F-01 Visueller Eingabe-Editor
- Der MathQuill-Editor ersetzt oder ergänzt STACK-Texteingabefelder
  (`data-stack-input-type="algebraic"`, `"numerical"`, `"equiv"`, `"textarea"`)
  in Quiz-Versuchen, Bewertungsansichten und Fragenvorschauen.
- Der Editor ist responsiv (Linebreaks bei kleinen/mobilen Displays, ab v1.1).

#### F-02 LaTeX-Maxima-Konvertierung
- Eingaben des Nutzers im MathQuill-Editor werden bidirektional konvertiert:
  - **LaTeX → Maxima** (`tex2max.js`): für die Übergabe an STACK
  - **Maxima → LaTeX** (`max2tex.js`): für die Anzeige gespeicherter Werte
- Konfigurierbare Behandlung impliziter Multiplikation (vier Modi):
  1. Explizit, Einzelzeichen-Variablen (`2ab` → `2*a*b`)
  2. Explizit, Mehrzeichenvariablen (`2ab` → `2*ab`)
  3. Leerzeichentrennung, Einzelzeichen (`2ab` → `2 a b`)
  4. Leerzeichentrennung, Mehrzeichen (`2ab` → `2 ab`)
  5. Unbehandelt (STACK/Maxima-„star options")

#### F-03 Konfigurierbare Toolbar
- Toolbar-Gruppen sind auf Instanzebene voreingestellt und auf Test- und
  Frageebene überschreibbar.
- Verfügbare Gruppen (gemäß `definitions.php`): Grundrechenarten, Brüche, Potenzen,
  Wurzeln, Vergleichsoperatoren, Klammern, Trigonometrie, Hyperbolische Funktionen,
  Exponential/Logarithmus, Integralrechnung, Differentialrechnung, Vektoren,
  Vektordifferential, Matrizen, Mengenlehre, Logik, Geometrie, Griechisch (groß/klein),
  Konstanten (Mathematik/Physik), Einheiten, Stochastik, Analysis, Absolutbetrag.

#### F-04 Editor-Aktivierungs-Steuerung (4-Stufen-Modell)
- **Stufe 0:** Global deaktiviert, kein Override möglich
- **Stufe 1:** Global aktiviert, kein Override möglich
- **Stufe 2:** Standard aus – kann per Test oder Frage aktiviert werden
- **Stufe 3:** Standard an – kann per Test oder Frage deaktiviert werden
- Override auf Quiz-Ebene und Frage-Ebene über `configure.php`

#### F-05 Konfigurationsseite
- Pro Test (mod_quiz) und pro Adaptive-Quiz (mod_adaptivequiz) gibt es eine
  dedizierte Konfigurationsseite (`configure.php`) mit Unterseite pro Frage.
- Konfigurationsdaten werden in der Tabelle `local_stackmatheditor` gespeichert
  (Felder: `cmid`, `questionbankentryid`, JSON-Konfiguration, `usermodified`).

#### F-06 Multi-Zeilen-Editor für Äquivalenzumformungen
- STACK-Eingaben vom Typ `equiv` und `textarea` erhalten einen mehrzeiligen
  MathQuill-Editor (`textarea_fields.js`).
- Jede Zeile ist eine separate MathQuill-Instanz; Zeilennavigation per
  Enter/Backspace/Cursor-Tasten.
- Gleichungssysteme werden mit geschweifter Klammer visualisiert
  (`.sme-equiv-system-brace`).

#### F-07 MathJax-Kompatibilität
- Moodle 4.3+ liefert MathJax v3; STACK und MathQuill erwarten die MathJax v2 Hub-API.
- Das Plugin installiert einen transparenten Shim (`mathjax_shim.js`) und einen
  vollständigen Compat-Layer (`mathjax_compat.js`).

#### F-08 Mehrsprachigkeit
- Vollständige Sprachpakete für Deutsch (`lang/de/`) und Englisch (`lang/en/`).

#### F-09 Datenschutz / Privacy API
- Das Plugin implementiert die Moodle Privacy API (`classes/privacy/provider.php`).

### 2.2 Nicht-funktionale Anforderungen

#### NF-01 Coding Standards
- Vollständige Compliance mit Moodle Coding Standards:
  - PHP: `phpcs --standard=moodle` ohne Errors
  - JS: ESLint (Moodle-Regelsatz) ohne Errors, Warnings minimiert
  - PHPDoc: `local/moodlecheck` ohne Errors

#### NF-02 Kompatibilität
- Moodle 4.4 und 4.5 (`$plugin->requires = 2024100700`)
- `qtype_stack >= 2024010400`
- `mod_quiz` und `mod_adaptivequiz`

#### NF-03 Performance
- AMD-Module werden über Moodle's Grunt-Pipeline gebaut (`amd/build/*.min.js`)
- Keine synchronen Lade-Blocker; MathJax-Integration über asynchrones Polling

#### NF-04 Testbarkeit
- PHPUnit-Tests für `config_manager`, `definitions`, `page_helper`, `quiz_helper`
- Behat-Tests für Editor-Rendering und Toolbar-Konfiguration

---

## 3. Pflichtenheft (Umsetzungsspezifikation)

### 3.1 Architektur

```
local/stackmatheditor/
├── amd/
│   ├── src/
│   │   ├── configure_links.js      # Link-Injektion in Quiz-Navigation
│   │   ├── input_fields.js         # Editor für algebraic/numerical-Inputs
│   │   ├── mathjax_compat.js       # MathJax v2 Hub → v3 vollständiger Compat-Layer
│   │   ├── mathjax_shim.js         # MathJax v2 Hub Minimal-Shim (früh geladen)
│   │   ├── mathquill_init.js       # MathQuill AMD-Lader und Boot-Sequenz
│   │   ├── max2tex.js              # Maxima → LaTeX Konverter
│   │   ├── tex2max.js              # LaTeX → Maxima Konverter
│   │   ├── textarea_fields.js      # Multi-Zeilen-Editor (equiv/textarea)
│   │   └── toolbar.js              # Toolbar-Builder (shared)
│   └── build/                      # Minifizierte Builds (terser / grunt amd)
├── classes/
│   ├── admin_setting_multiselect_sized.php
│   ├── config_manager.php          # CRUD für Plugin-Konfiguration pro Slot/Frage
│   ├── definitions.php             # Toolbar-Definitionen (Gruppen, Buttons, Einheiten)
│   ├── external/get_config.php     # Web Service: Konfiguration für AMD laden
│   ├── form/configure_form.php     # Moodleform für Konfigurationsseite
│   ├── hook_callbacks.php          # Moodle-Hooks (before_top_of_body, nav)
│   ├── output/
│   │   ├── configure_injector.php  # Rendert Konfigurationslinks
│   │   ├── editor_injector.php     # Injiziert AMD-Module in Quiz-Seite
│   │   ├── mathjax_injector.php    # Injiziert MathJax-Shim
│   │   └── page_helper.php         # Erkennt Quiz-Kontext (mod_quiz/adaptivequiz)
│   ├── privacy/provider.php
│   └── quiz_helper.php             # Lädt STACK-Fragen aus Quiz/AdaptiveQuiz-Kontext
├── db/
│   ├── access.php                  # Capabilities
│   ├── hooks.php                   # Hook-Registrierung
│   ├── install.xml                 # DB-Schema (local_stackmatheditor)
│   ├── services.php                # Web Service-Registrierung
│   └── upgrade.php
├── lang/de/ + lang/en/
├── tests/
│   ├── behat/                      # Behat-Szenarien
│   └── unit/                       # PHPUnit-Tests
├── thirdparty/mathquill/           # MathQuill (vendor, LGPLv3)
├── configure.php                   # Konfigurationsseite (Entry Point)
├── lib.php                         # Moodle-Hooks (extend_settings_navigation)
├── settings.php                    # Admin-Einstellungen
├── styles.css                      # Plugin-Styles
└── version.php
```

### 3.2 Datenbank

**Tabelle `local_stackmatheditor`**

| Spalte | Typ | Beschreibung |
|--------|-----|--------------|
| `id` | INT | PK |
| `cmid` | INT | Kursmodul-ID (Quiz oder AdaptiveQuiz) |
| `questionbankentryid` | INT NULL | Fragen-ID (NULL = Quiz-Standard) |
| `config` | TEXT | JSON: `{_enabled, _variableMode, groups: [...]}` |
| `usermodified` | INT | Moodle-User-ID |
| `timemodified` | INT | Unix-Timestamp |

### 3.3 Seitentyp-Erkennung

| Seite | `$PAGE->pagetype` | Erkennungsmerkmal |
|-------|-------------------|-------------------|
| Quiz-Versuch | `mod-quiz-attempt` | — |
| Quiz-Bewertung | `mod-quiz-review` | — |
| Fragenvorschau | `mod-quiz-question-preview` | — |
| AdaptiveQuiz-Versuch | `mod-adaptivequiz-view` | URL-Parameter `?cmid=` |
| AdaptiveQuiz-View | `mod-adaptivequiz-view` | URL-Parameter `?id=` |

### 3.4 Capability-Modell

| Capability | Verwendung |
|------------|------------|
| `mod/quiz:manage` | Zugriff auf Konfigurationsseite für mod_quiz |
| `moodle/course:manageactivities` | Zugriff auf Konfigurationsseite für mod_adaptivequiz (Schreibrecht; das Modul hat kein `:manage`, `:viewreport` ist nur ein Leserecht) |

### 3.5 Bekannte technische Einschränkungen

| Problem | Ursache | Status |
|---------|---------|--------|
| `max2tex::convert()` Komplexität 57 | Große Switch-/If-Kette für Maxima-Konstrukte | `eslint-disable` – Refactoring geplant |
| `tex2max::needsImplicitMultiplication()` Komplexität 25 | Token-Kombinations-Matrix | `eslint-disable` – Refactoring geplant |
| PHPUnit-Namespace-Warnung für Test-Klassen | Moodle erwartet `tests/tests/unit`, Plugin verwendet `tests/unit` | Tests laufen korrekt; Breaking Change zurückgestellt |

---

## 4. Offene Issues (Stand 2026-05-27)

| # | Titel | Priorität | Kategorie |
|---|-------|-----------|-----------|
| #27 | `or` in Äquivalenz → `o*r` | Hoch | tex2max-Bug |
| #28 | a/b-Button überdimensioniert | Mittel | CSS |
| #29 | Mischbruch-Implicit-Addition | Mittel | tex2max-Bug |
| #30 | `\pm` mit `sqrt(pi)` falsch | Mittel | tex2max/max2tex |
| #31 | `pi` statt `%pi` als Default | Niedrig | Konfiguration |
| #32 | Fraction Button (extern gemeldet) | Mittel | UI |
| #26 | Button-Rendering MathJAX-URL-abhängig | Mittel | Kompatibilität |
