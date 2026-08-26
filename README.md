# Scrutiny

[![CI](https://github.com/bleedingdeacons/scrutiny/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/scrutiny/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/scrutiny/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/scrutiny/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/scrutiny/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/scrutiny?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fscrutiny%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fscrutiny%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-1.30.0-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

**GDPR-compliant audit logging and personal data obscuring for Unity.**

Scrutiny is a WordPress plugin that hooks into the Unity plugin ecosystem to provide a tamper-evident audit trail of who accessed or changed personal data, and to mask that data in the admin UI for users who lack explicit clearance.

It is a required dependency of the **Amber** plugin and must be loaded before it.

**Requires:** WordPress 6.0+ · PHP 8.0+
**License:** MIT (Modified — see [License](#license))
**Author:** [The Bleeding Deacons](mailto:thebleedingdeacons@gmail.com)

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ≥ 8.1 |
| WordPress | ≥ 6.0 |
| Unity plugin | any |
| ACF (Advanced Custom Fields) | any |

---

## Installation

1. Place the `scrutiny` folder in `wp-content/plugins/`.
2. Ensure the **Unity** plugin is installed and activated first.
3. Activate **Scrutiny** via the WordPress plugin screen.

On activation, Scrutiny will:

- Create the `{prefix}scrutiny_audit_log` database table.
- Grant the `scrutiny_view_personal_data` capability to the `administrator` role.
- Grant the `scrutiny_edit_personal_data` capability to the `administrator` role.
- Grant the `scrutiny_edit_responder_certification` capability to the `administrator` role.

> **Order matters.** Scrutiny hooks into `unity/loaded` at priority `5`, before Amber (priority `10`), so that data-obscuring filters are in place before any ACF fields are rendered.

---

## Architecture

```
Scrutiny/
├── Scrutiny.php              # Plugin entry point, autoloader, hooks
└── src/
    ├── Plugin.php            # Service registration & lifecycle
    ├── Admin/
    │   └── AuditLogAdmin.php # Read-only admin UI for the audit trail
    ├── Fields/
    │   ├── AuditHistoryRenderer.php # Renders one record's trail as a table
    │   └── GdprAuditHistory.php     # ACF field type wrapping that renderer
    ├── Audit/
    │   ├── AuditLogger.php       # Writes log entries (no raw PII)
    │   ├── AuditRepository.php   # Database CRUD for the audit table
    │   ├── AuditTracker.php      # Hooks into Unity lifecycle events
    │   └── Interfaces/
    │       ├── AuditLoggerInterface.php
    │       └── AuditRepositoryInterface.php
    └── Privacy/
        ├── DataObscurer.php        # Masks personal data in admin/frontend
        ├── PersonalDataFields.php  # Field name constants & labels
        └── Interfaces/
            └── DataObscurerInterface.php
```

### Service graph

```
Unity Container
    └── AuditRepository       (persistence)
    └── AuditLogger           (uses AuditRepository)
    └── AuditTracker          (uses AuditLogger — hooks into Unity lifecycle)
    └── DataObscurer          (uses AuditLogger — hooks into ACF filters)
    └── AuditLogAdmin         (uses AuditRepository + AuditLogger — admin UI)
    └── AuditHistoryRenderer  (uses AuditRepository — one record's trail as HTML)
    └── GdprAuditHistory      (uses AuditHistoryRenderer — the ACF field type)
```

All services are resolved from Unity's PSR-11 container and are available after the `scrutiny_loaded` action fires.

---

## Features

### Audit Logging

Every access or change to a personal data field is recorded in a dedicated database table. Log entries contain:

| Column | Description |
|---|---|
| `action` | `view`, `create`, `update`, or `delete` |
| `entity_type` | `member`, `group`, or `meeting` |
| `entity_id` | WordPress post ID of the entity |
| `field_name` | Logical field name (e.g. `personal-email`) |
| `detail` | Human-readable context (e.g. `Value changed`) |
| `user_id` | WordPress user ID of the acting user |
| `user_login` | Username at the time of the event |
| `ip_address` | Anonymised IP (last IPv4 octet / last 80 IPv6 bits zeroed) |
| `logged_at` | UTC timestamp |

**No raw personal data values are ever stored in the log.**

Four values *are* recorded outright, because none of them is personal data — each names a service status or a public entity rather than the member:

* the responder-certification stage (e.g. `Changed to Certified`),
* the member's home group (e.g. `→ Thursday Big Book`),
* the member's intergroup position (e.g. `Telephone Liaison Officer →`),
* whether the member is their home group's GSR, named by that group (e.g. `→ Thursday Big Book`).

Knowing *which* stage, group or position is the whole point of auditing these fields; an entry reading `Value changed` would answer nothing. Details are kept terse because the action and field columns beside them already say what kind of event it is. The arrow runs from what the role was to what it became, and the side that does not exist is simply absent: `→ New` taken, `Old → New` moved, `Old →` given up. A group or position that no longer resolves is recorded as `#<id>` so the entry stays traceable.

#### Events tracked automatically

| Event | Hook | What is logged |
|---|---|---|
| Member edit form opened in admin | `current_screen` | Batch view of all personal data fields |
| Personal data ACF field loaded on frontend | `acf/load_value` | Per-field view (deduplicated per request) |
| Member fields changed | `unity/member_changing` | Individual field updates |
| Responder certification changed | `unity/member_changing` | The new stage by name, e.g. `Changed to Certified` |
| Home group assigned, changed or removed | `unity/member_changing` | The group by name, e.g. `Thursday Big Book → Sunday Steps` |
| Intergroup position assigned, changed or removed | `unity/member_changing` | The position by name, e.g. `→ Telephone Liaison Officer` |
| GSR taken, given up, or carried to a new home group | `unity/member_changing` | The group the role is held for, e.g. `→ Thursday Big Book` |
| Member created holding any of those roles | `unity/member_created` | One entry per role, e.g. `→ Sunday Steps` |
| Group contacts changed | `unity/group_changing` | Individual contact field updates |
| Meeting contacts changed | `unity/group_changing` | Individual contact field updates |
| Member permanently deleted | `before_delete_post` | Batch delete of all personal data fields, plus any role the member still held |
| Member moved to trash | `wp_trash_post` | Batch delete of all personal data fields, plus any role the member still held |

GSR is compared as *the group the member is GSR for*, not as the flag on its own. That folds three transitions into one entry — taking the role, giving it up, and carrying it to a new home group. The last of those changes no flag, so comparing `isGSR()` alone would log nothing and leave the trail showing a member who moved group while apparently still GSR of the old one. A GSR flag with no home group behind it is recorded as `(no group)` rather than dropped.

### Data Obscuring

`DataObscurer` hooks into ACF field rendering to hide personal data from users who do not hold the `scrutiny_view_personal_data` capability.

| Field | Obscured format |
|---|---|
| Email | `j•••@e•••.com` |
| Phone | `•••••123` (last 3 digits visible) |
| Name | `J••• S•••` (first character of each word visible) |

Obscuring is applied via:

- `acf/format_value` — frontend field rendering.
- `acf/prepare_field` — admin edit form rendering (value cleared, obscured value shown as placeholder).
- `acf/update_value` — prevents an empty placeholder submission from wiping the stored value.

### Admin Audit Log UI

A read-only **Audit Log** submenu page is added under the Intergroup menu, accessible only to `manage_options` users. It supports:

- Filtering by action, entity type, user, field name, and date range.
- Pagination (up to 200 entries per page).
- A nonce-protected **Purge** action to delete entries older than a configurable number of days.

---

### GDPR Audit History Field

Scrutiny registers a `gdpr_audit_history` field type with Advanced Custom
Fields. Add it to the member CPT's field group and the member's own edit screen
shows the audit trail recorded against that member — who viewed, created,
updated or called them, and when — without leaving the record.

It is display only. Like ACF's own layout fields it renders no input, so
nothing is posted, nothing is written to postmeta, and it is excluded from
REST. Requires ACF; with ACF inactive the field type is simply never
registered.

Four settings, in the field group editor:

| Setting | Default | Effect |
|---|---|---|
| Record type | `member` | Which `entity_type` to match. The audit log records groups, meetings and positions against post IDs the same way, so the field works on those too. |
| Entries to show | `20` | How many of the most recent entries to display, capped at 200 — the ceiling the repository enforces. The full total is always shown alongside. |
| Limit to action | *(all)* | Show only `view` entries, only `update` entries, and so on. |
| Show IP addresses | off | Adds the truncated IP each entry was recorded from. |

The field requires `manage_options` — the same capability as the Audit Log
page, since it is the same data. Sites that delegate member administration more
widely can lower that:

```php
add_filter('scrutiny_audit_history_capability', fn() => 'edit_others_posts');
```

Users below the Audit Log page's own bar still see the table, but not the
"View the full audit log" link that would only turn them away.

---

## Capabilities

| Capability | Default role | Effect |
|---|---|---|
| `scrutiny_view_personal_data` | `administrator` | Sees unobscured personal data values in admin and on the frontend |
| `scrutiny_edit_personal_data` | `administrator` | May update personal data fields (email, mobile number). Without this capability, changes are silently rejected and the existing value is preserved. Fields are shown as read-only in the admin UI. |
| `scrutiny_edit_responder_certification` | `administrator` | May change a member's responder-certification stage. Without this capability, the value stays visible but the field is shown read-only, and any save is silently rejected with the existing value preserved. |

Grant or revoke these capabilities via any standard WordPress role-management tool. A user may hold `scrutiny_view_personal_data` without `scrutiny_edit_personal_data` to allow viewing but not modifying personal data. The responder-certification value is never obscured — it is always visible — so `scrutiny_edit_responder_certification` gates editing only.

---

## Developer API

### Accessing the container

```php
$container = scrutiny(); // returns the shared Unity PSR-11 container
```

### Listening for plugin ready

```php
add_action('scrutiny_loaded', function (\Psr\Container\ContainerInterface $container) {
    // Scrutiny services are available
});
```

### Logging an event manually

```php
$logger = scrutiny()->get(\Scrutiny\Audit\Interfaces\AuditLogger::class);

// Single field
$logger->log('view', 'member', $postId, 'personal-email', 'Custom detail');

// Multiple fields at once
$logger->logBatch('delete', 'member', $postId, ['personal-email', 'mobile-number'], 'Bulk delete');
```

### Checking a user's clearance

```php
$obscurer = scrutiny()->get(\Scrutiny\Privacy\Interfaces\DataObscurer::class);

if ($obscurer->currentUserCanViewPersonalData()) {
    // show raw value
}
```

### Obscuring values programmatically

```php
$obscurer->obscureEmail('jane.doe@example.com'); // → j•••@e•••.com
$obscurer->obscurePhone('+447911123456');         // → •••••••••456
$obscurer->obscureName('Jane Doe');              // → J••• D••
```

---

## Personal Data Fields

All field name constants live in `Scrutiny\Privacy\PersonalDataFields`:

| Constant | Value |
|---|---|
| `PERSONAL_EMAIL` | `personal-email` |
| `MOBILE_NUMBER` | `mobile-number` |
| `GROUP_CONTACT_NAME` | `group-contact-name` |
| `GROUP_CONTACT_EMAIL` | `group-contact-email` |
| `GROUP_CONTACT_PHONE` | `group-contact-phone` |
| `MEETING_CONTACT_NAME` | `meeting-contact-name` |
| `MEETING_CONTACT_EMAIL` | `meeting-contact-email` |
| `MEETING_CONTACT_PHONE` | `meeting-contact-phone` |

---

## Database

The plugin creates a single custom table on activation:

```sql
CREATE TABLE {prefix}scrutiny_audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action      VARCHAR(20)  NOT NULL,
    entity_type VARCHAR(50)  NOT NULL,
    entity_id   BIGINT UNSIGNED NOT NULL,
    field_name  VARCHAR(100) NOT NULL,
    detail      VARCHAR(255) NOT NULL DEFAULT '',
    user_id     BIGINT UNSIGNED NOT NULL,
    user_login  VARCHAR(60)  NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
    logged_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_entity   (entity_type, entity_id),
    KEY idx_user     (user_id),
    KEY idx_action   (action),
    KEY idx_logged_at (logged_at),
    KEY idx_field    (field_name)
);
```

The table is created via `dbDelta`, so it is safe to run `Plugin::activate()` multiple times.

---

## Testing

Tests use PHPUnit 10 and live in `tests/Unit/`.

```bash
composer install
./vendor/bin/phpunit
```

Test suites cover:

- `GdprAuditLoggerTest` — log and logBatch behaviour, IP anonymisation.
- `GdprAuditRepositoryTest` — the audit-log query and write paths.
- `AuditTrackerTest` / `AuditTrackerGroupTest` / `AuditTrackerCoverageTest` — lifecycle hook integration.
- `MemberFieldsObscurerTest` / `GroupFieldsObscurerTest` / `PersonalDataFieldsTest` — obscuring and masking of personal data fields.
- `PersonalDataMinderTest` / `HasLoggerTest` — the member-edit script enqueue and the logging trait.
- `MemberPrunerTest` / `MemberTrashCleanerTest` / `PrunerCronTest` — GDPR retention cleanup.

Line coverage is reported to [Coveralls](https://coveralls.io/github/bleedingdeacons/scrutiny?branch=main)
on every CI run — see the coverage badge at the top of this file.

---

## Build

```bash
composer run build             # production build
composer run build:dev         # development build
composer run build:clean       # clean artefacts
```

---

## License

MIT (Modified) — see `LICENSE`.

---

## Authors

The Bleeding Deacons — thebleedingdeacons@gmail.com