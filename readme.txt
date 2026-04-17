=== Scrutiny ===
Contributors: thebleedingdeacons
Tags: audit, gdpr, privacy, logging, unity
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.16.0
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

GDPR-compliant audit logging and personal data obscuring for Unity. Required by Amber.

== Description ==

**GDPR-compliant audit logging and personal data obscuring for Unity.**

Scrutiny is a WordPress plugin that hooks into the Unity plugin ecosystem to provide a tamper-evident audit trail of who accessed or changed personal data, and to mask that data in the admin UI for users who lack explicit clearance.

It is a required dependency of the **Amber** plugin and must be loaded before it.

**Key features:**

= Audit Logging =

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

#### Events tracked automatically

| Event | Hook | What is logged |
|---|---|---|
| Member edit form opened in admin | `current_screen` | Batch view of all personal data fields |
| Personal data ACF field loaded on frontend | `acf/load_value` | Per-field view (deduplicated per request) |
| Member fields changed | `unity/member_changing` | Individual field updates |
| Group contacts changed | `unity/group_changing` | Individual contact field updates |
| Meeting contacts changed | `unity/group_changing` | Individual contact field updates |
| Member permanently deleted | `before_delete_post` | Batch delete of all personal data fields |
| Member moved to trash | `wp_trash_post` | Batch delete of all personal data fields |

= Data Obscuring =

`DataObscurer` hooks into ACF field rendering to hide personal data from users who do not hold the `scrutiny_view_personal_data` capability.

| Field | Obscured format |
|---|---|
| Email | `j•••@e•••.com` |
| Phone | `•••••123` (last 3 digits visible) |
| Name | `J••• S•••` (first character of each word visible) |

Obscuring is applied via:

* `acf/format_value` — frontend field rendering.
* `acf/prepare_field` — admin edit form rendering (value cleared, obscured value shown as placeholder).
* `acf/update_value` — prevents an empty placeholder submission from wiping the stored value.

= Admin Audit Log UI =

A read-only **Audit Log** submenu page is added under the Intergroup menu, accessible only to `manage_options` users. It supports:

* Filtering by action, entity type, user, field name, and date range.
* Pagination (up to 200 entries per page).
* A nonce-protected **Purge** action to delete entries older than a configurable number of days.

== Installation ==

1. Place the `scrutiny` folder in `wp-content/plugins/`.
2. Ensure the **Unity** plugin is installed and activated first.
3. Activate **Scrutiny** via the WordPress plugin screen.

On activation, Scrutiny will:

* Create the `{prefix}scrutiny_audit_log` database table.
* Grant the `scrutiny_view_personal_data` capability to the `administrator` role.

> **Order matters.** Scrutiny hooks into `unity/loaded` at priority `5`, before Amber (priority `10`), so that data-obscuring filters are in place before any ACF fields are rendered.

== Frequently Asked Questions ==

= Where can I get support? =

Contact The Bleeding Deacons at thebleedingdeacons@gmail.com.

== Screenshots ==

1. Plugin admin settings page.

== Changelog ==

= 1.9.1 =
* Current stable release.

== Upgrade Notice ==

= 1.9.1 =
Latest stable release of Scrutiny.

== Architecture ==

```
Scrutiny/
├── Scrutiny.php              # Plugin entry point, autoloader, hooks
└── src/
    ├── Plugin.php            # Service registration & lifecycle
    ├── Admin/
    │   └── AuditLogAdmin.php # Read-only admin UI for the audit trail
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

= Service graph =

```
Unity Container
    └── AuditRepository       (persistence)
    └── AuditLogger           (uses AuditRepository)
    └── AuditTracker          (uses AuditLogger — hooks into Unity lifecycle)
    └── DataObscurer          (uses AuditLogger — hooks into ACF filters)
    └── AuditLogAdmin         (uses AuditRepository + AuditLogger — admin UI)
```

All services are resolved from Unity's PSR-11 container and are available after the `scrutiny_loaded` action fires.

== Requirements ==

| Requirement | Version |
|---|---|
| PHP | ≥ 8.1 |
| WordPress | ≥ 6.0 |
| Unity plugin | any |
| ACF (Advanced Custom Fields) | any |

== Developer API ==

= Accessing the container =

```php
$container = scrutiny(); // returns the shared Unity PSR-11 container
```

= Listening for plugin ready =

```php
add_action('scrutiny_loaded', function (\Psr\Container\ContainerInterface $container) {
    // Scrutiny services are available
});
```

= Logging an event manually =

```php
$logger = scrutiny()->get(\Scrutiny\Audit\Interfaces\AuditLoggerInterface::class);

// Single field
$logger->log('view', 'member', $postId, 'personal-email', 'Custom detail');

// Multiple fields at once
$logger->logBatch('delete', 'member', $postId, ['personal-email', 'mobile-number'], 'Bulk delete');
```

= Checking a user's clearance =

```php
$obscurer = scrutiny()->get(\Scrutiny\Privacy\Interfaces\DataObscurerInterface::class);

if ($obscurer->currentUserCanViewPersonalData()) {
    // show raw value
}
```

= Obscuring values programmatically =

```php
$obscurer->obscureEmail('jane.doe@example.com'); // → j•••@e•••.com
$obscurer->obscurePhone('+447911123456');         // → •••••••••456
$obscurer->obscureName('Jane Doe');              // → J••• D••
```

== Database ==

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

== Capability ==

| Capability | Default role | Effect |
|---|---|---|
| `scrutiny_view_personal_data` | `administrator` | Sees unobscured personal data values in admin and on the frontend |

Grant or revoke this capability via any standard WordPress role-management tool.

== Personal Data Fields ==

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

== Testing ==

Tests use PHPUnit 10 and live in `tests/Unit/`.

```bash
composer install
./vendor/bin/phpunit
```

Test suites cover:

* `AuditLoggerTest` — log and logBatch behaviour, IP anonymisation.
* `AuditTrackerTest` / `AuditTrackerGroupTest` — lifecycle hook integration.
* `DataObscurerTest` — obscuring algorithms for email, phone, and name.
