# PHPCS Report — disciple-tools-crm-sync

Run: `vendor/bin/phpcs -w --report=full` (with warnings enabled; default run via `phpcs.xml` suppresses warnings with `<arg value="n"/>`)
Date: 2026-05-30

---

## Summary

| Category | Count |
|---|---|
| **Errors** | **0** |
| Warnings (auto-fixable with phpcbf) | ~85 |
| Warnings (require manual fix) | ~40 |
| Warnings in test files only | ~80 |

**Zero errors.** All violations are warnings. The default PHPCS run (without `-w`) is already clean because `phpcs.xml` suppresses warnings with `<arg value="n"/>`. The items below represent the full picture including warnings.

---

## Production Code Warnings

### 1. `error_log()` discouraged — 3 files

PHPCS treats any `error_log()` call as two warnings: `error_log() found. Debug code should not normally be used in production.` and `The use of function error_log() is discouraged`.

The affected calls are intentional, justified, and already carry `phpcs:ignore` comments where they are in the main bootstrap. The remaining three instances are missing those suppression comments:

| File | Line | Context |
|---|---|---|
| `admin/tabs/class-tab-automations.php` | 166 | `error_log()` inside a catch block |
| `connectors/connector-registry.php` | 85 | `error_log()` inside a catch block |
| `import/class-field-mapper.php` | 162 | `error_log()` inside a catch block |

**Recommendation:** Add `// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,Squiz.PHP.DiscouragedFunctions.Discouraged -- Intentional error logging in catch block.` on each of those lines, matching the pattern already used on the bootstrap `error_log()` call in the main plugin file.

---

### 2. `$wpdb->prepare()` placeholder count mismatch — 1 file (false positive)

| File | Line | PHPCS message |
|---|---|---|
| `import/class-message-importer.php` | 85 | `Incorrect number of replacements passed to $wpdb->prepare(). Found 1 replacement parameters, expected 2.` |

**Analysis:** False positive. The query uses a variadic spread `...array_merge([$meta_key, $dt_post_id], $page_msg_ids)` to pass a dynamic set of placeholders. The PHPCS sniff cannot count through the spread operator and mis-reports the count. The query is correct.

**Recommendation:** Add a `phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` comment on that line with a brief explanation.

---

### 3. Direct DB query without caching — 1 instance in production code

| File | Line | PHPCS message |
|---|---|---|
| `import/class-message-importer.php` | 84 | `Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().` |

**Analysis:** This is the same batch-deduplication query as #2 above (line 84–85). The query is intentional: it runs once per message page during import, and caching a per-import-run lookup table would add complexity without benefit. The existing `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery` comment already suppresses the `DirectQuery` part but does not suppress `NoCaching`.

**Recommendation:** Extend the existing ignore comment to also include `WordPress.DB.DirectDatabaseQuery.NoCaching`.

---

### 4. Assignment alignment warnings (auto-fixable) — 5 production files

All marked `[x]` — `vendor/bin/phpcbf` can fix them automatically.

| File | Warning count |
|---|---|
| `admin/class-readme-parser.php` | 8 |
| `connectors/metricool/metricool-api-client.php` | 2 |
| `connectors/metricool/metricool-connector.php` | 7 (array double arrow) |
| `disciple-tools-crm-sync.php` | 14 |
| `import/class-field-mapper.php` | 3 |
| `import/class-message-importer.php` | 3 |
| `import/import-processor.php` | 5 |
| `rest-api/rest-api.php` | 2 |
| `admin/tabs/class-tab-automations.php` | 1 |

**Recommendation:** Run `vendor/bin/phpcbf` — this resolves all of them at once.

---

### 5. Post-increment should be pre-increment — 1 instance

| File | Line | PHPCS message |
|---|---|---|
| `webhook/webhook-listener.php` | 135 | `Stand-alone post-increment statement found. Use pre-increment instead: ++$rl_data['count'].` |

Marked `[x]` — auto-fixable by `phpcbf`.

---

## Test Code Warnings (`test/`)

These do not affect the plugin's PHPCS clean status for the DT listing (the default run suppresses warnings). They are listed here for completeness.

### Alignment (auto-fixable)
All files under `test/` contain assignment / array double-arrow alignment warnings. All marked `[x]`. Run `phpcbf` to resolve.

Files affected: `test/bootstrap.php`, `test/integration/bootstrap.php`, `test/integration/CleanupTest.php`, `test/integration/MainClassTest.php`, `test/unit/AdminTabTest.php`, `test/unit/BrainMonkeyTestCase.php`, `test/unit/ConnectorTest.php`, `test/unit/ContactMatcherTest.php`, `test/unit/FieldMapperTest.php`, `test/unit/ImportProcessorTest.php`, `test/unit/MessageImporterTest.php`, `test/unit/RestFiltersTest.php`.

### `json_encode()` should be `wp_json_encode()` (test code)
Test files construct mock JSON payloads with PHP's native `json_encode()`. PHPCS warns to use `wp_json_encode()` instead.

Files: `test/unit/AdminTabTest.php:564`, `test/unit/ApiClientTest.php` (9×), `test/unit/ConnectorTest.php:125`, `test/unit/PollHandlerTest.php` (7×), `test/unit/RestApiTest.php:107`.

**Recommendation:** Replace `json_encode(` with `wp_json_encode(` in all test files. The function signature is identical for the purpose of building mock payloads.

### Reserved keyword parameter names in test mocks
Test mock classes use `$default` (in WP function stubs like `get_option($key, $default = '')`) and `$match`. These are PHPCS "recommended not to use" warnings, not errors.

Files: `test/unit/AdminTabTest.php` (6×), `test/unit/bootstrap.php`, `test/unit/ImportProcessorTest.php`, `test/unit/RestApiTest.php` (2×), `test/unit/RestFiltersTest.php`.

**Recommendation:** Rename the parameter in test stub signatures, e.g. `$default_value` instead of `$default`.

### Duplicate class names
- `test/testcase.php`: `TestCase` also defined in `test/integration/testcase.php`
- `test/unit/RestApiTest.php`: `RestApiTest` also defined in `test/integration/RestApiTest.php`
- `test/unit/bootstrap.php`: `Disciple_Tools_CRM_Sync` stub also defined in main plugin file

These are structural — the unit and integration bootstrap load different subsets of files. Not a runtime issue, but the duplication warning is valid for the shared `TestCase` and `RestApiTest` class names. Consider namespacing test classes or renaming (e.g. `Unit_RestApiTest` vs `Integration_RestApiTest`).

### Direct DB calls in `test/integration/CleanupTest.php`
`CleanupTest.php` calls `$wpdb->query()` directly for database teardown. These are test-infrastructure calls, not production code. PHPCS warns on all of them.

**Recommendation:** Add `// phpcs:ignore WordPress.DB.DirectDatabaseQuery` on each teardown query, or suppress at the class level.

### `parse_url()` in test (use `wp_parse_url()`)
`test/unit/MediaSideloaderTest.php:101` — replace `parse_url(` with `wp_parse_url(`.

### `base64_encode()` obfuscation warning
`test/unit/EncryptionTest.php:73,85` and `test/integration/WebhookPipelineTest.php:48` — benign uses (constructing test inputs for the encryption and webhook verification tests).
**Recommendation:** Add `// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test input construction.` on those lines.

### Possibly unused method overriding
`test/unit/ImportProcessorTest.php:86` — PHPCS flags a `setUp()` override as possibly useless. Not an issue unless the override has no body.

### Commented-out code in `test/unit/RegistryTest.php`
Lines 14, 36, 57 — PHPCS detects that inline comments are >50% valid PHP syntax. Likely leftover debugging lines. Remove them if not needed.

### Unused method parameters in test mocks
`test/integration/MediaSideloaderIntegrationTest.php:65`, `test/integration/MessageImporterIntegrationTest.php:229` — mock/stub methods that accept parameters to match a WP filter signature but don't use all of them. Add `phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter` with a note about the filter signature, matching the pattern already used in the main class.

---

## Recommended Actions (priority order)

1. **Run `vendor/bin/phpcbf`** — resolves all `[x]`-marked alignment warnings automatically (~85 warnings across all files).
2. **Add `phpcs:ignore` on 3 production `error_log()` calls** (`class-tab-automations.php:166`, `connector-registry.php:85`, `class-field-mapper.php:162`).
3. **Extend `phpcs:ignore` on `class-message-importer.php:84-85`** to cover `NoCaching` and `ReplacementsWrongNumber`.
4. **Replace `json_encode(` with `wp_json_encode(` in test files** (~18 occurrences).
5. **Rename `$default` parameter** in test stub signatures (~8 occurrences).
6. **Clean up `test/unit/RegistryTest.php`** — remove or rewrite the three lines flagged as commented-out code.
7. **Add `phpcs:ignore` on `base64_encode()` in test files** (benign encoding, 3 occurrences).
8. **Replace `parse_url(` with `wp_parse_url(`** in `test/unit/MediaSideloaderTest.php:101`.
9. **Add `phpcs:ignore`** on direct DB calls in `test/integration/CleanupTest.php` teardown.
10. **(Optional)** Rename duplicate test class names (`TestCase`, `RestApiTest`) to clarify unit vs integration distinction.
