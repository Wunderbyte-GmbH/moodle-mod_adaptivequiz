# CI-Baseline des Integrationsbranches (Issue #10, Phase 2)

Dieser Branch (`rebase/vtos-moodle500-alise`) beginnt bei `vtos/MOODLE_500`
(`a769068`, „Add Moodle 5 support", 2026-06-10). Das Dokument hält den Zustand
fest, der **vor** jeder Portierung erreicht wurde. Alle späteren Läufe werden
gegen diese Zahlen gestellt.

## Zielplattform

Entwickelt wird für **Moodle 5.3 (LTS)**, abgesichert per CI gegen 5.1 und 5.2.
Moodle 4.x und 5.0 werden nicht unterstützt.

```php
$plugin->requires  = 2025100600;   // Moodle 5.1
$plugin->supported = [501, 503];
```

Systemvoraussetzungen von Moodle 5.3 (`public/admin/environment.xml`):

| Komponente | Minimum |
|---|---|
| PHP | 8.3.0 |
| PostgreSQL | 17 |
| MariaDB | 11.4.0 |
| MySQL | 8.4 |

Eine Obergrenze fuer PHP deklariert Moodle 5.3 nicht; PHP 8.5 wird deshalb als
oberes Ende der Spanne mitgefahren.

Ab Moodle 5.1 liegt der Baum unter `public/`; Plugins gehören nach
`public/mod/adaptivequiz`, `config.php` bleibt im Projektwurzelverzeichnis.

## Gemessene Baseline

Suite `mod_adaptivequiz_testsuite`, Host **ohne** `local_catquiz` und ohne
`adaptivequizcatmodel_catquiz`, PHP 8.3.6, PostgreSQL 17.

| Moodle | Release | PHPUnit | Ergebnis |
|---|---|---|---|
| 5.1 | 5.1.6+ (Build 20260903) | 11.5.55 | **152 Tests, 433 Assertionen, grün** |
| 5.2 | 5.2.2+ (Build 20260903) | 11.5.55 | **152 Tests, 433 Assertionen, grün** |
| 5.3 | 5.3dev (Build 20260903) | 11.5.56 | **152 Tests, 433 Assertionen, grün** |

Zum Vergleich, aus der Inventur (Issue #10 Phase 1):

| Stand | Moodle 4.5.13 | Moodle 5.0.9 |
|---|---|---|
| `vtos/MOODLE_404` | 146 Tests, 343 Assertionen, grün | – |
| `vtos/MOODLE_500` | 147 Tests, 36 Fehler (nur mit gesenktem `requires`) | 152 Tests, 433 Assertionen, grün |
| `ralferlebach/v-3.0` | 152 Tests, 649 Assertionen, grün | 136 Tests, 16 Fehler |

## Matrix Moodle x PHP

Alle Laeufe gegen PostgreSQL 17, Suite `mod_adaptivequiz_testsuite`, Stand nach
Phase 3 (156 Tests / 446 Assertionen).

| | PHP 8.3.6 | PHP 8.4.25 | PHP 8.5.10 |
|---|---|---|---|
| Moodle 5.1.6+ | gruen | – | gruen |
| Moodle 5.2.2+ | gruen | – | gruen |
| Moodle 5.3dev | gruen | gruen | gruen |

MariaDB 11.4.13 auf Moodle 5.3 unter PHP 8.3: gruen.

Unter PHP 8.5 meldet PHPUnit drei Deprecations. Alle drei stammen aus dem
Moodle-Kern, nicht aus dem Plugin: `lib/dml/pgsql_native_moodle_database.php`
(null als Array-Offset, zweimal) und `lib/classes/plugininfo/base.php` bzw.
`user/profile/lib.php` je nach Zweig. Aus dem Plugin selbst kommt keine.

Drei plugin-eigene Deprecations wurden dabei behoben: implizit nullable
Parameter in `adaptivequiz_add_instance()`, `adaptivequiz_update_instance()` und
`mod_adaptivequiz_generator::create_instance()`. Ab PHP 8.4 verlangt PHP dort
die explizite Form `?Type $x = null`.

## Die einzige Anpassung, die für 5.3 nötig war

`adaptivequiz_supports()` deklarierte `FEATURE_GROUPMEMBERSONLY`. Ab Moodle 5.3
lehnt `public/lib/upgradelib.php` Plugins mit dieser Deklaration hart ab
(`detectedbrokenplugin`, MDL-83231). In 5.0 bis 5.2 ist die Konstante noch
definiert und die Prüfung nicht scharf.

**Zahn-Test durchgeführt.** Wichtig dabei: Die Prüfung greift ausschliesslich bei
Neuinstallation oder Upgrade, nicht bei einem bereits installierten Plugin. Ein
blosses Zurücksetzen der Datei im Spiegel bleibt deshalb grün und beweist nichts.
Der belastbare Ablauf ist:

```
Testdatenbank verwerfen, phpunit_dataroot leeren
  -> Zweig wieder eingesetzt : init bricht mit detectedbrokenplugin ab (rot)
  -> Zweig entfernt          : 152 Tests, 433 Assertionen (grün)
```

## Stand nach Phase 3

| Zustand | Ergebnis |
|---|---|
| Host aus dem Release-ZIP, kein Catmodel installiert | 152 Tests, 433 Assertionen, gruen |
| Arbeitsstand mit adaptivequizcatmodel_testcatmodel | 161 Tests, 459 Assertionen, gruen |

Beides ist die Abnahme aus Issue #10 §8: der Host laeuft ohne jedes Catmodel,
und er laeuft mit einem beliebigen neutralen Catmodel.

Der Vertrag umfasst sechs Erweiterungspunkte, aufgeloest ueber
`catmodel_resolver`, angewandt an vier Stellen:

| Stelle | Erweiterungspunkt |
|---|---|
| `adaptivequiz_add_instance()` | `catmodel_add_instance_handler` |
| `adaptivequiz_update_instance()` | `catmodel_update_instance_handler` |
| `adaptivequiz_delete_instance()` | `catmodel_delete_instance_handler` |
| `mod_form_extension::apply()` | `catmodel_mod_form_modifier` |
| `mod_form_extension::validate()` | `catmodel_mod_form_validator` |
| `mod_form_extension::preprocess()` | `catmodel_mod_form_data_preprocessor` |
| noch nicht aufgerufen | `item_administration_factory` |

### Was an der Item-Administration offen ist

Die Vertragsklassen `item_administration`, `item_administration_factory`,
`item_administration_evaluation` und `next_item` sind portiert, und der Resolver
findet eine Fabrik, die ein Catmodel anbietet. **Aufgerufen wird sie noch
nicht.** Das ist bewusst so ausgeliefert, und der Grund gehoert dokumentiert:

`attempt.php` wickelt einen Versuch prozedural ab. Antwortverarbeitung,
Schwierigkeitsberechnung, Abbruchpruefung, `redirect()` und Rendering liegen in
einem Skript ineinander. Um die eingebaute Logik hinter den Vertrag zu ziehen,
muesste sie aus dem Skript herausgeloest werden - der Fork hat dafuer
`attempt.php` neu geschrieben und `cat_session` eingefuehrt. Das ist keine
mechanische Portierung, sondern ein Umbau von rund 130 Zeilen Upstream-Code mit
`redirect()`-Aufrufen mittendrin, und er laesst sich mit den vorhandenen Tests
nicht bitgenau absichern.

Deshalb als eigener Arbeitsschritt vorgemerkt, mit dieser Reihenfolge:

1. `default_item_administration` aus `attempt.php` herausloesen, ohne
   Verhaltensaenderung; Nachweis ueber `attempt_test`, `catalgo_test`,
   `fetchquestion_test` und `locallib_test`.
2. `attempt.php` auf die Fabrik umstellen, Default wie bisher.
3. Erst dann die Verzweigung auf ein Catmodel einschalten.

Bis dahin ist `item_administration_factory` ein veroeffentlichter Vertrag ohne
Aufrufstelle im Host. Ein Catmodel kann ihn implementieren, der Host fragt ihn
noch nicht.

## CI-Stand

Der erste Lauf der Matrix fiel in allen dreizehn Jobs im Schritt „Moodle
installieren" aus. Ursache war eine einzige Zeile aus Issue #9: `DEFAULT=""` auf
der Spalte `password`. XMLDB lehnt den leeren String als Default fuer char- und
text-Spalten ab und meldet das per `debugging()`; `moodle-plugin-ci install`
bricht bei jeder Debug-Meldung ab. Der Core macht es in `mod_quiz` genauso:
NOT NULL ohne Default, gefuellt vom Code.

Seitdem sind die CI-Schritte lokal nachstellbar. Zwei Fallen dabei, beide haben
mich je einen CI-Lauf gekostet:

- Sie muessen gegen den **Spiegel** laufen, nicht gegen die Quelle. Der Sniff
  `moodle.Files.LangFilesOrdering` greift nur innerhalb eines Moodle-Baums - ein
  phpcs-Lauf im Quellverzeichnis meldet die Sprachdateien nie.
- Der PHPDoc-Checker braucht eine **normal installierte** Site, nicht nur die
  PHPUnit-Testdatenbank. Ohne sie stirbt er mit *Invalid component specified in
  renderer request* - und gibt trotzdem Exit 0 zurueck. Ein gruenes phpdoc ohne
  installierte Site sagt nichts aus. Einmal
  `php admin/cli/install_database.php` im Moodle-Baum genuegt.

| Schritt | Stand |
|---|---|
| `phplint` | Exit 0 |
| `phpcs --max-warnings 0 --exclude=PSR1.Classes.ClassDeclaration` | Exit 0 |
| `phpdoc --max-warnings 0` | Exit 0 - **nur gegen eine vollstaendig installierte Site aussagekraeftig** |
| `mustache` | Exit 0 |
| `phpunit --fail-on-warning` | 171 Tests / 500 Assertionen, PHP 8.3 und 8.4 |
| `grunt` | Exit 0 - nach `npm install` im Moodle-Baum auch lokal pruefbar |
| `behat` | lokal nicht pruefbar (Browsertreiber); in der CI **gruen**, 33 Szenarien / 1569 Schritte, deshalb scharf |

### Was die drei CI-Laeufe nacheinander zutage gefoerdert haben

Lauf 3 - die Pruefschritte muessen den **installierten** Pfad bekommen:

- `moodle-plugin-ci install` exportiert `PLUGIN_DIR`, den Zielpfad im Moodle-Baum.
  Mit dem Checkout-Pfad `./plugin` laufen phplint, phpcs und phpdoc zwar, der
  Mustache-Pruefer und Grunt aber nicht: beide arbeiten ausschliesslich innerhalb
  des Moodle-Baums (*File path passed ... is not within basename ... /moodle/public*,
  *Unable to find Gruntfile*). Alle Schritte verwenden jetzt `$PLUGIN_DIR`.
- **PHP 8.5 faellt vorerst aus der Matrix.** Moodles `composer.lock` haelt
  `ezyang/htmlpurifier` auf einer Fassung, die nur bis PHP 8.4 zugelassen ist;
  `composer install` im Moodle-Baum bricht deshalb ab, bevor irgendein Schritt
  laeuft. Ein eigener Job faehrt auf 8.5 weiterhin `phplint` und `phpcs` gegen
  den Plugincode - das geht ohne Moodle-Baum und faengt Syntax, die auf 8.5
  zerfaellt. PHP 8.4 laeuft dafuer auf allen drei Zweigen mit.
- **73 PHPDoc-Verstoesse** im Upstream-Code behoben. Der Checker vergleicht Typ
  und Name jedes Parameters positionsgenau mit der Signatur; halb reparierte
  Listen bestehen ihn nicht. `tools/phpdoc_params.py` baut die `@param`-Liste
  deshalb vollstaendig aus der Signatur neu auf, inklusive `?`-Nullable-Typen.

Zwei Fehler im ersten Workflow, beide im zweiten Lauf sichtbar geworden:

- **Das Plugin-Argument fehlte.** `moodle-plugin-ci phplint` und die uebrigen Schritte
  verlangen den Pfad als Argument; ohne ihn brechen sie mit *Not enough arguments
  (missing: "plugin")* ab. Lokal war das nie aufgefallen, weil ich die Schritte
  immer mit Pfad aufgerufen habe. Alle Schritte tragen jetzt `./plugin`.
- **Der Installationsschritt scheitert auf PHP 8.5.** `moodle-plugin-ci install`
  initialisiert auch Behat, und `admin/tool/behat/cli/util_single_run.php` meldet
  unter 8.5 Dutzende Deprecations aus dem Moodle-Kern. Auf 8.5 laeuft der
  Installer deshalb mit `--no-init`; PHPUnit, Behat und Grunt entfallen dort, die
  statischen Schritte liefern weiterhin ein echtes Ergebnis.

Zwei bewusste Ausnahmen im Workflow:

Lauf 6 - Grunt kam erstmals zum Zuge und meldete drei Dinge:

- **7x `space-before-function-paren`** in den AMD-Quellen. Reine Formatierung.
- **`promise/no-callback-in-promise`** in `question_banks_datasource.js`: der
  Callback der Autovervollstaendigung wurde direkt in `.then()` gereicht. Die
  Funktion ist jetzt `async` und ruft den Callback nach dem `await` auf, mit
  `try/catch` statt `.catch()`.
- **`declaration-no-important`** in `styles.css`. Moodles stylelint-Konfiguration
  verbietet `!important`. Die Klasse haengt an einem `<table>`, deshalb genuegt
  `table.usersattemptstable` - der Elementselektor schlaegt Bootstraps `.table`
  ueber die Spezifitaet, ohne `!important`.

Wer AMD-Quellen aendert, muss den Build nachziehen: `npx grunt amd
--root=public/mod/adaptivequiz` im Moodle-Baum, danach `amd/` in die Quelle
zurueckspiegeln. Sonst meldet der Grunt-Schritt geaenderte Build-Dateien.
`ci_check` faehrt den Schritt jetzt mit.

Lauf 5 - `$PLUGIN_DIR` ist im Workflow leer:

`moodle-plugin-ci install` schreibt `PLUGIN_DIR` und `MOODLE_DIR` nach `ci/.env`,
**nicht** nach `$GITHUB_ENV`. Im Shell-Kontext der Schritte ist die Variable also
leer, und `"$PLUGIN_DIR"` uebergibt ein leeres Argument - alle sieben Schritte
fielen auf *Failed to find '.../version.php'*.

Richtig ist, den Schritten **gar kein** Argument zu geben: moodle-plugin-ci
liest `.env` beim Start selbst und setzt `PLUGIN_DIR` als Vorgabe des
Pluginarguments ein (`AbstractPluginCommand::configure()`). Damit treffen auch
Mustache und Grunt den installierten Pfad. Der PHP-8.5-Job hat keinen
Installationsschritt und damit keine `.env`; dort bleibt `./plugin` stehen.

Lokal steht dafuer `ci_check` in `env.sh` bereit - es schreibt dieselbe `.env`
und faehrt die statischen Schritte ohne Argument.

Lauf 4 - ein Fehler, den nur MariaDB zeigt:

`provider_test::test_delete_removes_the_question_usage` fiel auf MariaDB, auf
PostgreSQL nicht. Ursache liegt im Kern: `delete_questions_usage_by_activities()`
nimmt auf MySQL und MariaDB einen eigenen Weg und loescht ueber
`DELETE qu, qa, qas, qasd FROM {question_usages} qu JOIN {question_attempts} qa`.
Der Join ist ein INNER JOIN - eine Fragennutzung **ohne** Fragen trifft er nicht,
und die Zeile in `question_usages` bleibt stehen. Auf PostgreSQL raeumt ein
separates `delete_records_select` sie mit weg.

Meine Vorrichtung hatte eine leere Fragennutzung angelegt. Sie enthaelt jetzt
eine echte Frage - so sieht ein Versuch im Betrieb aus, und der Kern-Sonderweg
greift dann korrekt. Personenbezogene Daten gehen dabei nicht verloren: eine
leere Nutzung traegt nur Kontext und Komponente.

- **`PSR1.Classes.ClassDeclaration` ausgenommen.** `mod_adaptivequiz_csv_renderer`
  ist ein Renderer-Subtyp und muss laut `renderer_factory` in `renderer.php`
  stehen. Auslagern wuerde das Laden brechen.
- **PHP 8.5 non-blocking.** Moodles `phpunit.xml` setzt `failOnDeprecation="true"`.
  Unter 8.5 melden sechs Kernstellen Deprecations, aus dem Plugin keine einzige.
  Der Lauf endet deshalb mit Exit 1, unabhaengig von `--fail-on-warning`.

## Privacy (Issue #6)

Das Modul hatte keinen Privacy-Provider. Ohne einen ist weder Auskunft noch
Loeschung moeglich, und ein DSGVO-konformer Produktivbetrieb scheidet aus.

`mod_adaptivequiz\privacy\provider` deckt jetzt ab:

| Gegenstand | Behandlung |
|---|---|
| `adaptivequiz_attempt` (zehn Spalten) | deklariert, exportiert, geloescht |
| Antworten des Versuchs | ueber `core_question` deklariert, exportiert und geloescht |
| Note im Gradebook | als Subsystem `core_grades` deklariert |
| Berichtseinstellungen | zwei Nutzereinstellungen deklariert |

Was **nicht** abgedeckt ist und offen bleibt: Catmodel-Subplugins koennen eigene
personenbezogene Daten halten - `local_catquiz` tut das mit den geschaetzten
Faehigkeiten je Skala. Der Host muesste dafuer die Subplugin-Delegation der
Privacy API implementieren, so wie `mod_quiz` es fuer `quizaccess` tut, und
jedes Catmodel einen eigenen Provider mitbringen. Das ist ein eigener
Arbeitsschritt; bis dahin ist die Auskunft fuer eine Installation mit Catmodel
unvollstaendig.

## Offene Punkte, die aus der Baseline folgen

**Code-Style.** Der Upstream-Stand erzeugt unter dem Moodle-Standard
**1675 Fehler und 8 Warnungen in 118 Dateien**, davon 1451 maschinell
korrigierbar. Grösste Gruppen: PSR2-Funktionsaufruf-Signaturen (229), lange
Array-Syntax (225), Operator-Abstände (336), fehlende Docblocks (96). Der
Upstream fährt kein phpcs-Gate.

Damit kollidieren zwei Vorgaben aus Issue #10: die Definition of Done verlangt
„Moodle Coding Style und PHPDoc vollständig grün", §14 verlangt „keine
unnötigen Format-Diffs in unverändertem Upstream-Code". Ein `phpcbf` über den
ganzen Baum macht jede künftige Upstream-Übernahme konfliktreich.

Vorschlag: Das phpcs-Gate wird erst **nach** Abschluss der Portierung scharf
geschaltet, und die maschinelle Korrektur erfolgt als ein einzelner, klar
benannter Format-Commit am Ende – nicht verteilt über die Portierungs-Commits.
Bis dahin gilt das Gate nur für Dateien, die in diesem Branch neu entstehen oder
inhaltlich geändert werden.

**Versionsnummer.** `vtos/MOODLE_500` trägt `$plugin->version = 2026030101`, der
bisherige Fork `2026090604`. Ein Upgrade einer bestehenden Fork-Installation auf
diesen Branch wäre damit ein Downgrade und wird von Moodle abgelehnt. Die
Release-Metadaten werden nach Issue #10 ohnehin erst am Ende der Konsolidierung
festgelegt; dieser Punkt ist dort mitzuentscheiden.

**`$plugin->cron = 0`** und die leere Funktion `adaptivequiz_cron()` sind
Altbestand aus der Zeit vor den Scheduled Tasks und können in Phase 4 entfallen.
