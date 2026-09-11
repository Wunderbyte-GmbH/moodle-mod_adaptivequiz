# PORTING_MATRIX – mod_adaptivequiz (Issue #10, Phase 1)

> **Status: VORLAGE, maschinell vorbefüllt.** Die Spalten *Zweck* und
> *Entscheidung* sind Vorschläge und zeilenweise zu bestätigen oder zu
> korrigieren; erst danach gilt die Matrix als Phase-1-Ergebnis nach #10.

Erzeugt am 2026-09-09. Reproduzierbar über `tools/porting_matrix.py`.

## Legende A–F

Die Kategorien stammen aus Issue #10, Abschnitt „2. Keine pauschalen Merge-Commits
der historischen Branches". Jede Änderung bekommt genau einen Buchstaben.

| Kat. | Bedeutung | Aktion auf dem Integrationsbranch |
|---|---|---|
| **A** | Upstream hat das bereits – gleichwertig oder neuer | nichts tun; Upstream-Fassung gewinnt |
| **B** | generische ALiSe-Verbesserung, nicht CATquiz-spezifisch | portieren, mit eigenem Regressionstest |
| **C** | Catmodel-/Subplugin-Host-API | portieren, als generischer Erweiterungspunkt |
| **D** | CATquiz-spezifisch | **nicht** in den Host; verbleibt im Adapter bzw. `local_catquiz` |
| **E** | veraltet, Regression oder durch Upstream ersetzt | verwerfen, Grund notieren |
| **F** | unklar | separat fachlich prüfen, danach nach A–E umschreiben |

„Zielschicht" sagt *wohin* etwas gehört (Host / Catmodel-API / Adapter / entfällt),
„Test" nennt den absichernden Regressionstest. Für **A** und **E** darf die
Testspalte leer bleiben, für **B** und **C** nicht.

## Referenzstände

| Kürzel | Branch | SHA | Datum | version.php |
|---|---|---|---|---|
| UP | `vtos:MOODLE_500` | `a769068` | 2026-06-10 | 2026030101 / 2.6.0dev / ALPHA / requires **2025041400** |
| UP404 | `vtos:MOODLE_404` | `7d64eb7` | 2025-10-05 | 2025092702 / **2.5.0 / STABLE** / requires 2024042200 |
| ALISE | `Wunderbyte:alise_adaptivequiz` | `0a80c7b` | 2024-10-10 | 2024123107 / 3.0.3dev |
| V300 | `Wunderbyte:version_3.0.0` | `98cbb8a` | 2025-10-07 | 2024102102 / 3.0.0 |
| ACV3 | `Wunderbyte:adaptive-catquiz-v3` | `cb40caa` | 2026-02-24 | 2024102102 / 3.0.0 |
| FORK | `ralferlebach:v-3.0` | `e05e170` | 2026-09-08 | 2026090604 / 3.0.0 / RC |

**Zentrale Struktureigenschaft:** `MOODLE_500` ist `MOODLE_404` **plus genau einem
Commit** (`a769068` „Add Moodle 5 support", 84 Dateien, +6562/−1153). In die
Gegenrichtung gibt es null Commits. Die beiden Upstream-Linien sind also nicht
divergiert – 404 ist der letzte stabile Stand, 500 ist derselbe Code plus die
Umstellung auf die Moodle-5-Fragensammlungen.

## Kennzahlen

281 Pfade über alle sechs Stände:

| Art | Anzahl | Vorschlag |
|---|---:|---|
| identisch UP ↔ FORK | 73 | A |
| divergiert UP ↔ FORK | 53 | 16× A, 37× F/B |
| nur im Fork | 42 | B / C |
| neu im 5.0-Commit (qbank) | 41 | A |
| nur im Upstream (schon in 404) | 17 | A, teils F |
| nur in historischen Branches | 55 | E |

Nach Kategorie: **A 144 · B 32 · C 10 · D 0 · E 55 · F 40**

**Kategorie D ist leer.** Volltextsuche nach `local_catquiz`, `catquiz_handler`,
`catscale`, `IRT`, `ALiSe`, `START.SMART`, `adaptivequizcatmodel_catquiz` liefert im
Host-Code von FORK, ALISE, V300 und ACV3 jeweils **0 Treffer**. Die Regel aus
#10 §13 ist heute erfüllt; sie gehört als CI-Gate verankert, damit es so bleibt.

---

## Moodle-4.5-Lauffähigkeit

Gemessen, nicht geschätzt. Alle Läufe: PHP 8.3.6, PostgreSQL 16, Suite
`mod_adaptivequiz_testsuite`.

| Host-Stand | Moodle 4.5.13 (PHPUnit 9.6) | Moodle 5.0.9 (PHPUnit 11.5) |
|---|---|---|
| `vtos:MOODLE_404` | **146 Tests, 343 Assertions, grün** | – |
| `vtos:MOODLE_500` | Installation abgelehnt (`requires 2025041400`); mit künstlich gesenktem `requires`: **147 Tests, 36 Fehler** | **152 Tests, 433 Assertions, grün** |
| `ralferlebach:v-3.0` | **152 Tests, 649 Assertions, grün** | 136 Tests, **16 Fehler**, 57 Deprecations |

### Was 4.5 konkret bricht

Die 36 Fehler zerfallen in exakt zwei Ursachen – keine dritte:

**1. `mod_qbank` fehlt in 4.5 – 32 von 36 Fehlern.**
Meldung durchgängig: *Component mod_qbank does not support generators yet.*
Die eigenständige Fragensammlung als Aktivität existiert erst ab Moodle 5.0.
Der 5.0-Commit stellt die Itembank von *verknüpften Fragekategorien* auf
*verknüpfte Fragensammlungen* um und nutzt dafür
`core_question\local\bank\question_bank_helper` – in 4.5 nicht vorhanden
(`question/classes/local/bank/question_bank_helper.php` existiert dort nicht,
`mod/qbank` ebenso wenig). Betroffen im Plugin: `itembank.php`, `view.php`,
`classes/item_bank.php`, `classes/item_bank_helper.php`,
`classes/external/search_question_banks.php`, `classes/form/assign_question_bank_form.php`,
die zugehörigen `classes/output/item_bank_*`, die `amd/src/item_bank*`-Module und
die Templates `item_bank_*`/`question_bank*`.

**2. PHPUnit-11-Attribute in den Upstream-Tests – 4 von 36 Fehlern.**
Der 5.0-Commit hat `@dataProvider`-Annotationen auf `#[DataProvider]`-Attribute
umgestellt (`tests/lib_test.php`, `tests/local/fetchquestion_test.php`,
`tests/locallib_test.php`). PHPUnit 9.6 – das Moodle 4.5 mitbringt – ignoriert
Attribute, der Datensatz kommt nicht an, Ergebnis `ArgumentCountError: Too few
arguments`. Das ist ein reines Testthema, kein Produktivcode.

### Was Lauffähigkeit unter 4.5 verlangt

Drei gangbare Wege, in aufsteigendem Aufwand:

**Weg 1 – zwei Upstream-Basen, wie vtos es selbst macht.** Integrationsbranch für
4.5 auf `MOODLE_404`, Integrationsbranch für 5.x auf `MOODLE_500`, ALiSe-Portierung
einmal geschrieben und in beide gemerged. Vorteil: keine Kompatibilitätsschicht,
beide Seiten laufen gegen den Code, für den sie gedacht sind – `MOODLE_404` ist
gemessen grün auf 4.5. Nachteil: zwei Branches dauerhaft zu pflegen; die
ALiSe-Portierung muss zweimal grün gehalten werden.

**Weg 2 – eine Basis mit Itembank-Abstraktion.** `MOODLE_500` als einzige Basis, die
Itembank hinter einem Interface mit zwei Implementierungen: kategoriebasiert (4.5)
und fragensammlungsbasiert (5.x), Auswahl über `$CFG->branch` bzw.
`class_exists(question_bank_helper::class)`. Der Datenbankteil trägt das bereits:
laut Upstream-README bleiben verknüpfte Kategorien nach dem Upgrade unverändert
bestehen, es gibt also weiterhin beide Verknüpfungsarten. Betrifft ~15 Dateien
plus Templates. Zusätzlich müssen die vier `#[DataProvider]`-Stellen doppelt
annotiert werden (Attribut **und** Annotation), damit PHPUnit 9 und 11 beide
den Datensatz finden. Vorteil: ein Branch. Nachteil: eine Abstraktion, die der
Upstream nicht hat – genau die Art Divergenz, die #10 §14 klein halten will.

**Weg 3 – 4.5 aufgeben.** Host nur noch ab 5.0, `local_catquiz` zieht nach.
Widerspricht Issue #31, das ausdrücklich `supported = [405, 502]` fordert, also
4.5 **und** 5.x. Nur tragfähig, wenn diese Anforderung fällt.

### Entscheidung (Stand 2026-09-09)

**Weg 3 – keine 4.x-Unterstützung, keine Wartungslinie.** Die veröffentlichte
Version `local_catquiz 1.3.0` / `mod_adaptivequiz 3.0.0` ist reine 5.x-Software.
Der bestehende 4.5-Stand (`local_catquiz 1.2.0`, inoffiziell) bleibt als Artefakt
bestehen und wird nicht weitergepflegt.

**Zielversion: Moodle 5.3 (LTS).** Abgesichert wird per CI zusätzlich gegen 5.1
und 5.2. Daraus folgt für alle drei Plugins:

```php
$plugin->requires  = 2025100600;   // Moodle 5.1
$plugin->supported = [501, 503];
```

5.3 erscheint am 5. Oktober 2026 (Code-Freeze war am 24. August 2026) und wird bis
1. Oktober 2029 sicherheitsgestützt. Bis zur Verzweigung von `MOODLE_503_STABLE`
wird gegen `moodle/main` (`5.3dev`) entwickelt.

Konsequenzen für diese Matrix:

- **Block 3c (41 qbank-Dateien) ist Kategorie A** – sie kommen mit der Basis.
- **Die 16 Divergenzen mit `±404 = 0` sind Kategorie A.** Sie stammen allein aus
  dem 5.0-Commit; der Fork war dort mit dem letzten 4.x-Upstream identisch.
- Die Itembank-Abstraktion aus dem verworfenen Weg 2 **entfällt ersatzlos**.
- Die vier `#[DataProvider]`-Stellen brauchen **keine** Doppelannotation.
- Basis bleibt `vtos/MOODLE_500` – der einzige 5.x-Branch, den vtos anbietet. Sein
  README nennt 5.0, 5.1 und 5.2; **5.3 ist unsere eigene Arbeit.**

### Gemessene Baseline der Upstream-Basis über die Zielversionen

`vtos/MOODLE_500`, Suite `mod_adaptivequiz_testsuite`, PHP 8.3.6:

| Zielversion | Struktur | PHPUnit | DB | Ergebnis |
|---|---|---|---|---|
| Moodle 5.1.6+ | `public/` | 11.5.55 | PostgreSQL 17 | **152 Tests, 433 Assertions, grün** |
| Moodle 5.2.2+ | `public/` | – | – | noch nicht vermessen |
| Moodle 5.3dev | `public/` | 11.5.56 | PostgreSQL 17 (Pflicht) | **152 Tests, 433 Assertions, grün** – nach einer Ein-Zeilen-Korrektur |

**Die einzige 5.3-Bruchstelle der Upstream-Basis:** `adaptivequiz_supports()`
deklariert `FEATURE_GROUPMEMBERSONLY`. Ab 5.3 lehnt `upgradelib.php` Plugins mit
dieser Deklaration hart ab (`detectedbrokenplugin`, MDL-83231) – Installation
unmöglich, kein Test läuft an. In 5.0, 5.1 und 5.2 ist die Konstante noch
definiert und die Prüfung nicht scharf; der Fehler tritt also ausschliesslich auf
5.3 auf. Nach Entfernen des `case`-Zweigs läuft die Suite unverändert grün.
Betrifft `ralferlebach/v-3.0` gleichermassen und gehört als **B** in die Portierung.

### Zwei Umgebungsänderungen ab 5.1 bzw. 5.3

1. **`public/`-Webroot ab 5.1.** Der Baum liegt unter `<root>/public/`; Plugins
   gehören nach `public/local/catquiz` bzw. `public/mod/adaptivequiz`, `config.php`
   bleibt im Projektwurzelverzeichnis. Betrifft jeden Spiegel-, Installations- und
   Deploymentpfad – und ist genau das, was `local_catquiz` #32 fordert.
2. **PostgreSQL ≥ 17 ab 5.3.** Der Umgebungscheck bricht auf 16.x ab
   (*„version 17 is required"*). Die CI-Matrix muss für 5.3 einen anderen
   Datenbankdienst fahren als für 4.5/5.0.

---

## Block 1 – Divergierte Dateien (UP ↔ FORK)

`±500` = geänderte Zeilen gegen `MOODLE_500`, `±404` gegen `MOODLE_404`.
Wo `±404 = 0` ist, ist der Fork identisch mit dem letzten stabilen Upstream-Stand –
die Divergenz stammt dann **allein aus dem 5.0-Commit** und ist Kategorie **A**.
Die Differenz zwischen beiden Spalten zeigt, wie viel der Divergenz upstream-
und wie viel fork-verursacht ist.

| Datei | ±500 | ±404 | Berührte Funktionen (Auszug) | Zweck (auszufüllen) | Zielschicht | Entsch. | Test |
|---|---:|---:|---|---|---|---|---|
| `tests/local/attempt_test.php` | 857 | 810 | `it_can_check_if_a_user_has_a_completed_attempt_on_a_quiz`, `it_fails_to_set_quba_for_an_attempt_with_an_invalid_argument`, `setup_test_data_xml`, `test_find_last_quest_used_by_attempt`, `test_find_last_umarked_question_using_bad_data`, `test_get_all_questions_in_attempt` | | | F | |
| `tests/locallib_test.php` | 702 | 656 | `attempts_allowed_data`, `attempts_allowed_data_fail`, `event_is_triggered_on_attempt_completion`, `fail_attempt_data`, `setup_test_data_xml`, `test_adaptivequiz_min_attempts_reached` | | | F | |
| `tests/local/catalgo_test.php` | 360 | 335 | `setup_test_data_xml`, `test_get_current_diff_level`, `test_get_current_diff_level_using_attempt_obj_missing_highestlevel`, `test_get_current_diff_level_using_attempt_obj_missing_lowestlevel`, `test_get_current_diff_level_using_level_zero`, `test_get_current_diff_level_using_no_attempt_obj` | | | F | |
| `mod_form.php` | 422 | 307 | `add_cat_model_chooser_when_applicable`, `completion_rule_enabled`, `definition_after_data`, `validate_cat_model_fields_or_skip`, `validate_questions_pool`, `validation` | | | F | |
| `README.md` | 306 | 293 | `add_instance_callback`, `data_preprocessing_callback`, `definition_after_data_callback`, `delete_instance_callback`, `update_instance_callback`, `validation_callback` | | | F | |
| `lib.php` | 282 | 255 | `adaptivequiz_add_instance`, `adaptivequiz_apply_attempt_feedback_editor`, `adaptivequiz_catmodel_add_instance_callback`, `adaptivequiz_catmodel_delete_instance_callback`, `adaptivequiz_catmodel_update_instance_callback`, `adaptivequiz_pluginfile` | | | F | |
| `classes/local/attempt.php` | 207 | 207 | `find_last_quest_used_by_attempt`, `get_attempt`, `get_question_level`, `initialize_quba`, `set_last_difficulty_level`, `set_quba_id` | | | F | |
| `attempt.php` | 160 | 153 | – | | | F | |
| `tests/completion/custom_completion_test.php` | 134 | 130 | `setup_test_data_xml`, `test_completionvalidresult_requires_a_valid_result`, `test_it_defines_completion_state_based_on_attempt_completion` | | | F | |
| `renderer.php` | 231 | 123 | `attempt_controls_or_notification`, `attempt_feedback`, `attempt_finished_page`, `attempts_number`, `display_start_attempt_form_secured`, `item_bank_page` | | | F | |
| `tests/generator_test.php` | 236 | 112 | `test_it_creates_a_completed_attempt`, `test_it_creates_an_in_progress_attempt`, `test_it_creates_links_with_question_banks`, `test_it_creates_links_with_single_question_categories`, `test_it_handles_question_category_names_when_creating_an_instance` | | | F | |
| `classes/output/user_attempt_summary.php` | 87 | 87 | `__construct`, `export_for_template`, `from_db_records` | | | F | |
| `classes/local/catalgo.php` | 86 | 86 | `get_current_diff_level`, `return_current_diff_level` | | | F | |
| `db/upgrade.php` | 98 | 77 | – | | | F | |
| `tests/generator/lib.php` | 139 | 77 | `create_completed_attempt`, `create_in_progress_attempt`, `create_link_with_question_bank`, `create_link_with_question_category`, `get_question_category_id_list_by_names` | | | F | |
| `tests/behat/attempt_feedback.feature` | 106 | 74 | – | | | F | |
| `classes/output/ability_measure.php` | 73 | 73 | `__construct`, `as_object_to_format`, `export_for_template`, `of_attempt_on_adaptive_quiz` | | | F | |
| `attemptfinished.php` | 70 | 70 | – | | | F | |
| `classes/output/questionanalysis/renderer.php` | 52 | 52 | – | | | F | |
| `grade.php` | 44 | 44 | – | | | F | |
| `backup/moodle2/restore_adaptivequiz_stepslib.php` | 32 | 32 | – | | | F | |
| `locallib.php` | 60 | 32 | – | | | F | |
| `delattempt.php` | 31 | 31 | – | | | F | |
| `lang/en/adaptivequiz.php` | 74 | 27 | – | | | F | |
| `classes/completion/custom_completion.php` | 26 | 26 | – | | | F | |
| `tests/lib_test.php` | 215 | 24 | `item_administration_params_data`, `test_adaptivequiz_delete_instance`, `test_item_administration_params_can_be_updated_for_an_adaptive_quiz_instance`, `test_item_administration_params_update_cannot_affect_other_adaptivequiz_properties`, `test_questioncat_association_insert`, `test_questioncat_association_update` | | | F | |
| `view.php` | 89 | 19 | – | | | F | |
| `classes/local/fetchquestion.php` | 55 | 17 | `__destruct`, `retrieve_question_categories`, `store_tagquestsum_in_session` | | | F | |
| `backup/moodle2/backup_adaptivequiz_stepslib.php` | 13 | 13 | – | | | F | |
| `db/install.xml` | 31 | 11 | – | | | F | |
| `questionanalysis/overview.php` | 9 | 9 | – | | | F | |
| `questionanalysis/single.php` | 8 | 8 | – | | | F | |
| `styles.css` | 11 | 7 | – | | | F | |
| `version.php` | 8 | 6 | – | | | F | |
| `closeattempt.php` | 3 | 3 | – | | | F | |
| `tests/attempt_state_change_observers_test.php` | 7 | 3 | – | | | F | |
| `classes/attempt_state_change_observers.php` | 2 | 2 | – | | | F | |
| `classes/local/repository/questions_repository.php` | 4 | **0** | – | | | A | |
| `db/access.php` | 67 | **0** | – | | | A | |
| `tests/behat/adaptive_algorithm.feature` | 22 | **0** | – | | | A | |
| `tests/behat/add_activity.feature` | 68 | **0** | – | | | A | |
| `tests/behat/attempt.feature` | 48 | **0** | – | | | A | |
| `tests/behat/attempt_delete.feature` | 24 | **0** | – | | | A | |
| `tests/behat/completion_rule_attempt_complete.feature` | 22 | **0** | – | | | A | |
| `tests/behat/question_analysis.feature` | 24 | **0** | – | | | A | |
| `tests/local/fetchquestion_test.php` | 69 | **0** | `constructor_throw_coding_exception_provider`, `it_fails_when_instantiated_with_a_difficulty_level_as_a_string`, `it_fails_when_instantiated_with_a_negative_difficulty_level`, `it_fails_when_instantiated_with_a_zero_difficulty_level`, `it_retrieves_all_tag_ids`, `it_throws_an_exception_when_retrieves_all_tag_ids_for_an_empty_tag_prefix` | | | A | |
| `tests/local/report/questions_difficulty_range_test.php` | 13 | **0** | – | | | A | |
| `tests/local/report/users_attempts/user_preferences/filter_user_preferences_test.php` | 13 | **0** | – | | | A | |
| `tests/local/report/users_attempts/user_preferences/user_preferences_repository_test.php` | 13 | **0** | – | | | A | |
| `tests/local/report/users_attempts/user_preferences/user_preferences_test.php` | 13 | **0** | – | | | A | |
| `tests/local/repository/questions_repository_test.php` | 88 | **0** | – | | | A | |
| `tests/local/repository/tags_repository_test.php` | 15 | **0** | – | | | A | |
| `tests/renderer_test.php` | 18 | **0** | – | | | A | |

## Block 2 – Nur im Fork vorhanden

Kandidaten für Portierung. `C` wurde vergeben, wo der Pfad das Catmodel-/
Subplugin-Konstrukt trägt, sonst `B`. *Ursprung* nennt die historische Linie mit
identischem Inhalt (`*` = dort vorhanden, aber abweichend).

| Datei | Ursprung | Zweck (auszufüllen) | Zielschicht | Entsch. | Test |
|---|---|---|---|---|---|
| `.gitattributes` | - | |  | B | |
| `.gitignore` | - | |  | B | |
| `amd/build/cat_model_chooser.min.js` | ALISE,V300,ACV3 | |  | B | |
| `amd/build/cat_model_chooser.min.js.map` | ALISE,V300,ACV3 | |  | B | |
| `amd/src/cat_model_chooser.js` | ALISE,V300,ACV3 | |  | B | |
| `classes/cat_session.php` | V300/ACV3 | |  | B | |
| `classes/local/itemadministration/default_item_administration.php` | V300,ACV3 | |  | B | |
| `classes/local/itemadministration/default_item_administration_factory.php` | V300,ACV3 | |  | B | |
| `classes/local/itemadministration/item_administration.php` | V300,ACV3 | |  | B | |
| `classes/local/itemadministration/item_administration_evaluation.php` | V300,ACV3 | |  | B | |
| `classes/local/itemadministration/item_administration_factory.php` | V300,ACV3 | |  | B | |
| `classes/local/itemadministration/next_item.php` | V300,ACV3 | |  | B | |
| `classes/local/user_attempts_table.php` | V300,ACV3 | |  | B | |
| `classes/output/attempts_number.php` | V300,ACV3 | |  | B | |
| `lang/de/adaptivequiz.php` | ALISE | |  | B | |
| `settings.php` | V300,ACV3 | |  | B | |
| `tests/behat/attempts_report.feature` | V300,ACV3 | |  | B | |
| `tests/behat/completion_valid_result.feature` | - | |  | B | |
| `tests/behat/continued_attempt.feature` | V300,ACV3 | |  | B | |
| `tests/behat/user_report.feature` | V300,ACV3 | |  | B | |
| `tests/calculation_steps_test.php` | V300,ACV3 | |  | B | |
| `tests/cat_session_test.php` | V300/ACV3 | |  | B | |
| `tests/fixtures/calcsteps/1.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/2.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/3.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/4.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/5.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/6.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/7.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/8.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/instances.csv` | V300,ACV3 | |  | B | |
| `tests/fixtures/calcsteps/questionpool.csv` | V300,ACV3 | |  | B | |
| `catmodel/README.md` | - | | Catmodel-API | C | |
| `catmodel/upgrade.txt` | V300,ACV3 | | Catmodel-API | C | |
| `classes/local/catmodel/form/catmodel_mod_form_data_preprocessor.php` | V300,ACV3 | | Catmodel-API | C | |
| `classes/local/catmodel/form/catmodel_mod_form_modifier.php` | ALISE,V300,ACV3 | | Catmodel-API | C | |
| `classes/local/catmodel/form/catmodel_mod_form_validator.php` | ALISE,V300,ACV3 | | Catmodel-API | C | |
| `classes/local/catmodel/instance/catmodel_add_instance_handler.php` | ALISE,V300,ACV3 | | Catmodel-API | C | |
| `classes/local/catmodel/instance/catmodel_delete_instance_handler.php` | ALISE,V300,ACV3 | | Catmodel-API | C | |
| `classes/local/catmodel/instance/catmodel_update_instance_handler.php` | ALISE,V300,ACV3 | | Catmodel-API | C | |
| `classes/plugininfo/adaptivequizcatmodel.php` | ALISE,V300,ACV3 | | Catmodel-API | C | |
| `db/subplugins.json` | ACV3 | | Catmodel-API | C | |

## Block 3 – Im Upstream vorhanden, im Fork nicht

### 3a – Vom Fork entfernt, obwohl schon in `MOODLE_404` (3)

Der einzige Block, der hier wirklich eine Entscheidung braucht.

| Datei | historisch in | Grund der Entfernung (auszufüllen) | Entsch. |
|---|---|---|---|
| `templates/ability_measure.mustache` | ALISE | | F |
| `templates/attempt_finished_page.mustache` | ALISE | | F |
| `tests/behat/report.feature` | ALISE | | F |

### 3b – In `MOODLE_404` vorhanden, im Fork nie (14)

<details><summary>Liste aufklappen</summary>

- `classes/attempt.php`
- `classes/attempt_feedback_placeholder_option.php`
- `classes/attempt_feedback_placeholders_helper.php`
- `classes/editor_placeholder_option.php`
- `classes/editor_placeholders.php`
- `classes/external/ability_measure_exporter.php`
- `classes/output/attempt_feedback.php`
- `classes/output/attempt_finished_page.php`
- `classes/output/editor_placeholders.php`
- `classes/output/user_attempts_overview.php`
- `templates/editor_placeholder.mustache`
- `templates/editor_placeholders_desc.mustache`
- `tests/attempt_test.php`
- `tests/external/ability_measure_exporter_test.php`

</details>

### 3c – Neu mit dem 5.0-Commit, qbank-Umstellung (41)

Kategorie **A** auf einer 500er-Basis, **entfällt** auf einer 404er-Basis. Diese
Liste ist zugleich die Aufwandsschätzung für Weg 2 aus dem 4.5-Abschnitt.

<details><summary>Liste aufklappen</summary>

- `amd/build/item_administration_params.min.js`
- `amd/build/item_administration_params.min.js.map`
- `amd/build/item_bank.min.js`
- `amd/build/item_bank.min.js.map`
- `amd/build/question_banks_datasource.min.js`
- `amd/build/question_banks_datasource.min.js.map`
- `amd/src/item_administration_params.js`
- `amd/src/item_bank.js`
- `amd/src/question_banks_datasource.js`
- `classes/external/search_question_banks.php`
- `classes/form/assign_question_bank_form.php`
- `classes/form/item_administration_params_form.php`
- `classes/item_administration_params_helper.php`
- `classes/item_bank.php`
- `classes/item_bank_helper.php`
- `classes/output/attempt_debug_info.php`
- `classes/output/item_administration_params.php`
- `classes/output/item_bank_notification.php`
- `classes/output/item_bank_page.php`
- `classes/output/item_bank_qbanks.php`
- `classes/output/item_bank_qcategories.php`
- `classes/output/start_attempt.php`
- `db/services.php`
- `itembank.php`
- `templates/attempt_debug_info.mustache`
- `templates/item_administration_parameter.mustache`
- `templates/item_administration_params.mustache`
- `templates/item_bank_notification.mustache`
- `templates/item_bank_page.mustache`
- `templates/item_bank_qbanks.mustache`
- `templates/item_bank_qcats.mustache`
- `templates/item_bank_settings_block.mustache`
- `templates/question_bank.mustache`
- `templates/question_banks_block.mustache`
- `templates/start_attempt.mustache`
- `tests/behat/behat_mod_adaptivequiz.php`
- `tests/behat/calculation_steps.feature`
- `tests/external/search_question_banks_test.php`
- `tests/generator/behat_mod_adaptivequiz_generator.php`
- `tests/item_bank_helper_test.php`
- `tests/item_bank_test.php`

</details>

## Block 4 – Nur in historischen Branches (55)

Weder im aktuellen Upstream noch im aktuellen Fork. Vorschlag durchgehend **E**.
Zu prüfen ist nur, ob darunter etwas versehentlich verlorenging.

<details><summary>Liste aufklappen</summary>

- `amd/build/attempt_report_chart_data_indices.min.js` (in ALISE)
- `amd/build/attempt_report_chart_data_indices.min.js.map` (in ALISE)
- `amd/src/attempt_report_chart_data_indices.js` (in ALISE)
- `answerdistributiongraph.php` (in ALISE)
- `attemptgraph.php` (in ALISE)
- `catmodel/catquiz/classes/local/catmodel/form/mod_form_extension.php` (in ALISE)
- `catmodel/catquiz/classes/local/catmodel/instance/instance_actions_handler.php` (in ALISE)
- `catmodel/catquiz/classes/local/catmodel/itemadministration/catquiz_item_administration.php` (in ALISE)
- `catmodel/catquiz/classes/local/catmodel/itemadministration/catquiz_item_administration_factory.php` (in ALISE)
- `catmodel/catquiz/lang/en/adaptivequizcatmodel_catquiz.php` (in ALISE)
- `catmodel/catquiz/lib.php` (in ALISE)
- `catmodel/catquiz/version.php` (in ALISE)
- `classes/local/adaptive_quiz_requires.php` (in ALISE)
- `classes/local/adaptive_quiz_session.php` (in ALISE)
- `classes/local/attempt/attempt.php` (in ALISE)
- `classes/local/attempt/cat_calculation_steps_result.php` (in ALISE)
- `classes/local/attempt/cat_model_params.php` (in ALISE)
- `classes/local/catalgorithm/catalgo.php` (in ALISE)
- `classes/local/catalgorithm/determine_next_difficulty_result.php` (in ALISE)
- `classes/local/catalgorithm/difficulty_logit.php` (in ALISE)
- `classes/local/delete_attempt.php` (in ALISE)
- `classes/local/itemadministration/item_administration_using_default_algorithm.php` (in ALISE)
- `classes/local/question/difficulty_questions_mapping.php` (in ALISE)
- `classes/local/question/question_answer_evaluation.php` (in ALISE)
- `classes/local/question/question_answer_evaluation_result.php` (in ALISE)
- `classes/local/question/questions_answered_summary.php` (in ALISE)
- `classes/local/question/questions_answered_summary_provider.php` (in ALISE)
- `classes/local/report/answers_summary.php` (in ALISE)
- `classes/local/report/answers_summary_per_difficulty.php` (in ALISE)
- `classes/local/report/user_own_attempts_sql_and_params.php` (in ALISE)
- `classes/local/report/user_own_attempts_sql_resolver.php` (in ALISE)
- `classes/local/report/user_own_attempts_table.php` (in ALISE)
- `classes/output/attempt/attempt_finished_feedback.php` (in ALISE)
- `classes/output/attempt/attempt_finished_page.php` (in ALISE)
- `classes/output/report/answerdistributiongraph/answer_distribution_graph_dataset.php` (in ALISE)
- `classes/output/report/answerdistributiongraph/answer_distribution_graph_dataset_point.php` (in ALISE)
- `classes/output/report/answerdistributiongraph/answer_distribution_graph_page.php` (in ALISE)
- `classes/output/report/attemptgraph/attempt_graph_dataset.php` (in ALISE)
- `classes/output/report/attemptgraph/attempt_graph_dataset_point.php` (in ALISE)
- `classes/output/report/attemptgraph/attempt_graph_page.php` (in ALISE)
- `pix/icon.png` (in ALISE)
- `pix/icon.svg` (in ALISE)
- `pix/monologo.png` (in ALISE)
- `templates/attempt_finished_feedback.mustache` (in ALISE)
- `tests/local/activityinstance/questions_difficulty_range_test.php` (in ALISE)
- `tests/local/attempt/attempt_test.php` (in ALISE)
- `tests/local/attempt/cat_model_params_test.php` (in ALISE)
- `tests/local/catalgorithm/catalgo_test.php` (in ALISE)
- `tests/local/catalgorithm/difficulty_logit_test.php` (in ALISE)
- `tests/local/itemadministration/item_administration_evaluation_test.php` (in ALISE)
- `tests/local/itemadministration/item_administration_using_default_algorithm_test.php` (in ALISE)
- `tests/local/itemadministration/next_item_test.php` (in ALISE)
- `tests/local/question/question_answer_evaluation_result_test.php` (in ALISE)
- `tests/local/question/question_answer_evaluation_test.php` (in ALISE)
- `tests/local/report/answers_summary_per_difficulty_test.php` (in ALISE)

</details>

## Block 5 – Identisch UP ↔ FORK (73)

Kategorie **A**, keine Aktion, nur zur Vollständigkeit der Inventur.

<details><summary>Liste aufklappen</summary>

- `amd/build/attempt_administration_chart_dataset_config.min.js`
- `amd/build/attempt_administration_chart_dataset_config.min.js.map`
- `amd/build/attempt_administration_chart_output.min.js`
- `amd/build/attempt_administration_chart_output.min.js.map`
- `amd/build/attempt_administration_chart_output_htmltable.min.js`
- `amd/build/attempt_administration_chart_output_htmltable.min.js.map`
- `amd/build/attempt_answers_distribution_chart_manager.min.js`
- `amd/build/attempt_answers_distribution_chart_manager.min.js.map`
- `amd/src/attempt_administration_chart_dataset_config.js`
- `amd/src/attempt_administration_chart_output.js`
- `amd/src/attempt_administration_chart_output_htmltable.js`
- `amd/src/attempt_answers_distribution_chart_manager.js`
- `backup/moodle2/backup_adaptivequiz_activity_task.class.php`
- `backup/moodle2/restore_adaptivequiz_activity_task.class.php`
- `classes/event/attempt_completed.php`
- `classes/event/course_module_instance_list_viewed.php`
- `classes/event/course_module_viewed.php`
- `classes/form/requiredpassword.php`
- `classes/local/attempt/attempt_state.php`
- `classes/local/questionanalysis/attempt_score.php`
- `classes/local/questionanalysis/question_analyser.php`
- `classes/local/questionanalysis/question_result.php`
- `classes/local/questionanalysis/quiz_analyser.php`
- `classes/local/questionanalysis/statistics/answers_statistic.php`
- `classes/local/questionanalysis/statistics/answers_statistic_result.php`
- `classes/local/questionanalysis/statistics/discrimination_statistic.php`
- `classes/local/questionanalysis/statistics/discrimination_statistic_result.php`
- `classes/local/questionanalysis/statistics/percent_correct_statistic.php`
- `classes/local/questionanalysis/statistics/percent_correct_statistic_result.php`
- `classes/local/questionanalysis/statistics/question_statistic.php`
- `classes/local/questionanalysis/statistics/question_statistic_result.php`
- `classes/local/questionanalysis/statistics/times_used_statistic.php`
- `classes/local/questionanalysis/statistics/times_used_statistic_result.php`
- `classes/local/report/attempt_report_helper.php`
- `classes/local/report/individual_user_attempts/filter.php`
- `classes/local/report/individual_user_attempts/table.php`
- `classes/local/report/questions_difficulty_range.php`
- `classes/local/report/users_attempts/filter/filter.php`
- `classes/local/report/users_attempts/filter/filter_form.php`
- `classes/local/report/users_attempts/filter/filter_options.php`
- `classes/local/report/users_attempts/sql/sql_and_params.php`
- `classes/local/report/users_attempts/sql/sql_resolver.php`
- `classes/local/report/users_attempts/user_preferences/filter_user_preferences.php`
- `classes/local/report/users_attempts/user_preferences/user_preferences.php`
- `classes/local/report/users_attempts/user_preferences/user_preferences_form.php`
- `classes/local/report/users_attempts/user_preferences/user_preferences_repository.php`
- `classes/local/report/users_attempts/users_attempts_table.php`
- `classes/local/repository/questions_number_per_difficulty.php`
- `classes/local/repository/tags_repository.php`
- `classes/output/attempt_progress.php`
- `classes/output/report/attempt_administration_report.php`
- `classes/output/report/attempt_answers_distribution_report.php`
- `classes/output/report/individual_user_attempts/individual_user_attempt_action.php`
- `classes/output/report/individual_user_attempts/individual_user_attempt_actions.php`
- `db/events.php`
- `db/log.php`
- `db/uninstall.php`
- `index.php`
- `lang/fr/adaptivequiz.php`
- `module.js`
- `pix/attemptgraph.png`
- `pix/monologo.svg`
- `reviewattempt.php`
- `templates/attempt_administration_report.mustache`
- `templates/attempt_answers_distribution_report.mustache`
- `templates/attempt_progress.mustache`
- `templates/attempt_report_chart.mustache`
- `templates/report/individual_user_attempt_actions.mustache`
- `tests/fixtures/mod_adaptivequiz.xml`
- `tests/fixtures/mod_adaptivequiz_adaptiveattempt.xml`
- `tests/fixtures/mod_adaptivequiz_catalgo.xml`
- `tests/fixtures/mod_adaptivequiz_findquestion.xml`
- `viewattemptreport.php`

</details>

---

## Offene Fragen an die Fachseite

1. **Basis-Entscheidung zuerst:** Weg 1, 2 oder 3 aus dem 4.5-Abschnitt. Davon
   hängt ab, ob Block 3c Kategorie A oder gar keine Rolle spielt – und ob die
   Portierung ein- oder zweimal grün gehalten werden muss.
2. Die vier grössten fork-eigenen Divergenzen im Produktivcode gegen `MOODLE_404`:
   `mod_form.php` (±307), `lib.php` (±255), `classes/local/attempt.php` (±207),
   `attempt.php` (±153). Sie bestimmen den Aufwand von Phase 4.
3. Der Fork hat die Upstream-Namensräume `classes/local/attempt/*`,
   `classes/local/catalgorithm/*` und `classes/local/question/*` flachgezogen
   (`classes/local/attempt.php`, `classes/local/catalgo.php`). Bewusste
   ALiSe-Entscheidung oder Altstand aus `alise_adaptivequiz`?
4. `db/install.xml` und `db/upgrade.php` (±77 gegen 404): Welche Fork-Felder sind
   fachlich noch nötig? #10 §7 verbietet zeilenweises Zusammenkopieren.
5. Die Vorarbeiten zu #9, #8 und #4 liegen in `attemptfinished.php` (±70),
   `mod_form.php` und `db/install.xml`. Die Heuristik stuft sie als **F** ein;
   sie müssen auf **B** gesetzt werden, sonst gehen sie beim Rebase verloren.
