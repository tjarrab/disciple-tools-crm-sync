![Plugin Banner](https://raw.githubusercontent.com/tjarrab/disciple-tools-crm-sync/master/documentation/banner.png)

# Disciple.Tools - CRM Sync

A WordPress plugin that imports and syncs contacts from CRM platforms into [Disciple.Tools](https://disciple.tools), with message history, webhook automation, and scheduled polling.

---

## Purpose

Disciple.Tools - CRM Sync bridges your CRM platform and your Disciple.Tools instance. Contacts are imported with their message history, and kept in sync via configurable webhooks and scheduled polling filters. Each import run creates or updates DT contacts and records conversation history as comments.

The plugin is built around a **connector abstraction layer**: each supported CRM is implemented as a separate connector class that extends `Disciple_Tools_CRM_Sync_Abstract_Connector`. Connectors are registered via the `dt_crm_sync_connectors` WordPress filter, so third-party connectors can be added without modifying the core plugin. [Respond.io](https://respond.io) is the bundled first-party connector. There is a metricool example for how to add another connector, however, it has only been tested minimally. 

---

## Usage

#### Will Do

- Import contacts from a connected CRM platform into Disciple.Tools
- Sync message and conversation history as DT contact comments
- Translate incoming messages to English using AI (Google Gemini) with configurable daily limits
- Receive real-time contact updates via CRM webhook triggers
- Run scheduled polling filters to automatically import matching contacts on a configurable interval
- Support multiple CRM platforms through the connector extension API
- Encrypt CRM credentials at rest using AES-256-CBC

#### Will Not Do

- Push Disciple.Tools contact data back to the connected CRM
- Replace or bypass the connected CRM platform
- Modify, delete, or create records in the connected CRM
- Operate without an active Disciple.Tools theme installation

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1+ |
| WordPress | 6.0+ |
| Disciple.Tools theme | 1.47+ |
| PHP extension | `openssl` (required for AES-256-CBC credential encryption) |

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   wp-content/plugins/disciple-tools-crm-sync/
   ```
2. In WordPress admin, go to **Plugins → Installed Plugins** and activate **Disciple.Tools - CRM Sync**.
3. Navigate to **Extensions → CRM Sync** in the DT admin menu.

---

## Configuration

### Tab 1 — Configuration

**Connector**

Select the active CRM from the **Connector** dropdown. Credential fields on this tab are rendered dynamically based on the selected connector — different connectors will show different fields.

**Credentials** *(example: Respond.io)*

1. Enter your Respond.io **Base URL** (default: `https://api.respond.io`).
2. Enter your Respond.io **Bearer Token** (API key from your Respond.io workspace settings).
3. Enter your **Webhook Signing Key** (set when creating the webhook in Respond.io).
4. Click **Test Connection** to verify the API key is valid.
5. Click **Refresh Schema** to fetch your Respond.io custom field definitions.
6. Map Respond.io custom fields to DT contact fields in the **Field Mapping** table.
7. Click **Save Settings**.

Secrets (Bearer Token and Signing Key) are encrypted at rest using AES-256-CBC with a plugin-specific key stored in `wp_options`. They are never written to the DOM.

**Data Retention**

The **Delete all CRM metadata from DT contacts when uninstalling** checkbox controls whether connector-specific post meta and comment meta are removed when the plugin is uninstalled. This is **off by default** — contact sync history is preserved unless explicitly enabled.

> ⚠️ This action is irreversible. Enable only if you intend a full data purge.

### Tab 2 — Importer

Browse and search contacts from your CRM, select one or more, and click **Import Selected** to queue them for import into DT. Import jobs run in the background via WP-Cron and results appear in **Tab 4 — Sync Logs**.

### Tab 3 — Automations

**Webhook** — Copy the webhook URL and configure it in a workflow trigger in your CRM. See [Webhook Setup](#webhook-setup) below. The plugin handles signature verification automatically.

**Saved Filters** — Create named filter rules with a polling interval that automatically import matching contacts on a recurring schedule. The available filter fields (e.g. search query, tag) are provided dynamically by the active connector and rendered in both this tab and the Importer's filter bar.

Each filter has a **Do not update existing contacts** option (checked by default). When enabled, contacts that are already in DT are skipped and only new contacts are created. Uncheck this if you want the scheduled run to also refresh fields on existing contacts.

### Tab 4 — Sync Logs

Paginated log of all import activity with status filter (Success / Failed / Skipped / Merged). Each row links directly to the imported DT contact.

### Tab 5 — Translation

Configure AI-powered message translation for incoming CRM messages. When enabled, all message text is sent to the AI provider before being saved as a DT contact comment. If translation fails or the daily limit is reached, the original message is preserved.

**Provider**: Currently supports Google Gemini. Enter your API key (encrypted at rest), choose a model from the dropdown, and save. Use **Refresh Models** to fetch the latest available models from Google.

**Prompt**: Customize the instruction sent to the AI. The default prompt asks Gemini to translate non-English text into English while preserving formatting. The message text is appended directly after your prompt.

**Daily Limit**: Set a maximum number of translations per 24-hour rolling window to control API costs. Set to `0` for unlimited. The current usage counter and reset timer are displayed below this field.

**Test Translation**: Use the test button to verify your API key and model are working before enabling translation on live imports.

---

## Cron Setup

WordPress's built-in pseudo-cron (`wp-cron.php`) only fires when the site receives web traffic. On low-traffic sites this can cause significant delays for scheduled imports.

**Recommended setup** — disable WP-Cron and use a real system cron job:

1. Add the following line to `wp-config.php` (before the `/* That's all, stop editing! */` line):
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

2. Add a system cron job that hits `wp-cron.php` every 5 minutes. Edit your crontab (`crontab -e`) and add:
   ```
   */5 * * * * wget -q -O /dev/null "https://yoursite.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
   ```
   Replace `yoursite.com` with your actual domain.

---

## Webhook Setup

The plugin exposes a single webhook endpoint for all connectors:

```
https://yoursite.com/wp-json/disciple-tools-crm-sync/v1/webhook
```

Configure this URL as the destination in your CRM's workflow or automation trigger. Signature verification is handled automatically by the active connector's `verify_webhook()` method using an HMAC key stored in the connector credentials.

---

## Adding a new CRM connector

To add support for a new CRM, create a PHP class that extends `Disciple_Tools_CRM_Sync_Abstract_Connector` and register it via the `dt_crm_sync_connectors` filter.

### 1. Create the connector class

Place the file at `connectors/{slug}/{slug}-connector.php`. All abstract methods must be implemented:

| Method | Returns | Description |
|---|---|---|
| `get_slug()` | `string` | Machine-readable slug used as the registry key (e.g. `hubspot`). Must be a valid `sanitize_key()` value. |
| `get_label()` | `string` | Human-readable name shown in the admin connector dropdown (e.g. `HubSpot`). |
| `get_credential_fields()` | `array` | Ordered list of credential field definitions. Each entry: `['slug'=>string, 'label'=>string, 'type'=>'text\|password\|url']`. |
| `get_dt_source_slug()` | `string` | Value stored in the DT contact `sources` field for imported contacts (e.g. `hubspot`). |
| `get_dt_source_label()` | `string` | Human-readable label for the DT contact source entry. |
| `get_meta_key_prefix()` | `string` | Prefix prepended to all post-meta keys written by the import processor. Must start and end with `_` (e.g. `_hubspot_`). |
| `test_connection()` | `bool\|WP_Error` | Verify stored credentials with a lightweight API request. |
| `get_field_schema()` | `array\|WP_Error` | Fetch the remote CRM's custom field schema. Implementations should cache the result. |
| `get_contacts(array $filter_params, ?string $cursor, int $limit)` | `array\|WP_Error` | Paginated contact list. Return shape: `['data'=>[], 'cursor'=>['next'=>string\|null]]`. |
| `get_contact(string $id)` | `array\|WP_Error` | Single contact's full profile by its connector-native ID. |
| `get_filter_fields()` | `array` | Filter UI field definitions for the Automations tab and the Importer's filter bar. Each entry: `['slug'=>string, 'label'=>string, 'type'=>'text\|select', 'options'=>array (optional)]`. |

The following methods have default no-op implementations and can be overridden as needed:

| Method | Default | Description |
|---|---|---|
| `get_messages(string $contact_id, ?string $cursor, int $limit)` | Returns empty | Paginated message history for a contact. |
| `get_webhook_header()` | `''` | HTTP header name carrying the HMAC signature (WP-normalised, e.g. `x_signature`). Empty string disables webhook support. |
| `verify_webhook(string $raw_body, string $signature)` | `false` | Verify the HMAC signature of an incoming webhook payload. |
| `refresh_schema_cache()` | no-op | Invalidate any locally cached field schema. |

See [`connectors/abstract-connector.php`](connectors/abstract-connector.php) for full PHPDoc on all methods.

### 2. Register the connector

Add a named function (not a closure) hooked to `dt_crm_sync_connectors` so it can be removed via `remove_filter()` if needed:

```php
function my_crm_register_connector( array $connectors ): array {
    $connectors['my_crm'] = 'My_CRM_Connector_Class_Name';
    return $connectors;
}
add_filter( 'dt_crm_sync_connectors', 'my_crm_register_connector' );
```

The registry resolves the class name to an instance via `Disciple_Tools_CRM_Sync_Connector_Registry::make( $slug, $credentials )`.

---

## Troubleshooting

### Scheduled syncs not firing

1. Verify that a real system cron job is configured (see [Cron Setup](#cron-setup) above).
2. Check that the saved filter is listed in **Tab 3 — Automations** and has a valid **Next Run** time.
3. Run `wp cron event list` (WP-CLI) or check `wp_options` for `cron` to confirm the `dt_crm_sync_poll` hook is scheduled.
4. Ensure the PHP `openssl` extension is loaded — the batch processor aborts if API credentials cannot be decrypted.

### Import jobs not appearing in logs

WP-Cron runs asynchronously — jobs scheduled via **Import Selected** are queued with a 5-second delay. Wait for the next cron fire, then refresh **Tab 4 — Sync Logs**. If logs remain empty after several minutes, check `wp-content/debug.log` for PHP errors.

### 429 Rate Limit errors

The plugin handles HTTP 429 responses automatically: the current batch is paused and all remaining contacts are rescheduled via WP-Cron with the `Retry-After` delay from the response header. No manual intervention is required. Check **Tab 4 — Sync Logs** to confirm the rescheduled batch completed.

#### Respond.io connector

**"API token could not be decrypted" notice**

The stored ciphertext is corrupted or the encryption key has been changed. Re-enter your Bearer token in **Tab 1 → Credentials** and save. The plugin generates a unique encryption key on activation that is **intentionally retained on uninstall**, so re-activating normally restores decryption without requiring key re-entry.

**Broken field mappings warning in Tab 1**

A Respond.io custom field that was previously mapped has been renamed or deleted. Click **Refresh Schema** to update the schema, then re-save the field mapping — the broken entry will be dropped and you can create a new mapping for the renamed field.

---

## Contribution

Contributions welcome. You can report issues and bugs in the [Issues](https://github.com/tjarrab/disciple-tools-crm-sync/issues) section of the repository. 

Visit the [Disciple.Tools Community](https://disciple.tools) for more information about the Disciple.Tools project.

---

## Changelog

### 1.0.7

- Added conversation status filter for respond.io — automations can now target contacts by conversation status

### 1.0.6

- Fixed translation timeouts on contacts with long message histories — messages are now sent to AI translation in small chunks rather than one large request, with automatic retry on timeout. Chunk size and request timeout are both configurable on the Translation settings tab

### 1.0.5

- Fixed message ordering in conversation history — contact messages were sorting before agent messages after translation batching was introduced. Timestamps are now derived from the message ID, which is a microsecond epoch for all message types, so incoming messages (which have an empty `status` array) are no longer treated as if they arrived at time zero
- Message history now shows the contact's name instead of the generic "Contact" label, falling back to the DT contact name or "Contact" if no name is available
- Timestamps in the conversation log now include the day of the week and seconds, e.g. `2025-05-26, Monday, 14:30:00 UTC`
- Message history viewer redesigned as a chat thread with contact messages on the left and agent messages on the right

### 1.0.4

- Added a message history viewer, when messages are mapped to a DT field, the raw field is hidden and replaced with a "View Message History" link that opens a clean, readable HTML page in a new tab
- Switched Gemini translation to a single batch API call per contact instead of one call per message, to mitigate against PHP timeouts on long conversation histories
- Corrected a few issues uncovered in code reviews 

### 1.0.3

- Added the ability to map Respond.io messages to different DT fields
- Added the ability to tag the contact source based on the originating social media platform

### 1.0.2

- Fixing an issue with the cron tasks not cleaning up nicely

### 1.0.1

- Added the ability to filter respond.io contacts by lifecycle
- Added AI translation by gemini for all messages, with a daily cut-off to avoid budget issues

### 1.0.0

- Initial public release as Disciple.Tools - CRM Sync
- Connector abstraction layer for multi-CRM support via the `dt_crm_sync_connectors` filter
- Bundled Respond.io connector with contact import, message history sync, webhook support, and scheduled polling
- AES-256-CBC encryption for CRM credentials stored in wp_options
- Saved filter automations with hourly, every-2-hours, every-4-hours, every-8-hours, and daily polling intervals
- Sync log viewer with status filtering (Success / Failed / Skipped / Merged) and direct links to imported DT contacts
- Rate-limit handling: automatic rescheduling on HTTP 429 / 449 responses
