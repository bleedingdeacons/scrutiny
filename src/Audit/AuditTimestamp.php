<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use function get_option;
use function wp_date;
use function wp_timezone;

/**
 * Audit timestamp formatting.
 *
 * Audit rows store `logged_at` in UTC (see {@see GdprAuditLogger::log()}).
 * Every surface that displays one has to convert it to the site's configured
 * timezone and format it with the site's own date and time settings, so the
 * conversion lives here rather than being copied per surface — the Audit Log
 * admin page and the GdprAuditHistory ACF field both call it.
 */
final class AuditTimestamp
{
    /**
     * Format a stored UTC audit timestamp for display in the site's timezone.
     *
     * Returns the raw stored value unchanged when it cannot be parsed, or when
     * wp_date() fails, so a malformed row still shows something rather than an
     * empty cell.
     *
     * @param string $loggedAt The `logged_at` column value, in UTC.
     * @return string
     */
    public static function forDisplay(string $loggedAt): string
    {
        try {
            $utc   = new DateTimeImmutable($loggedAt, new DateTimeZone('UTC'));
            $local = $utc->setTimezone(wp_timezone());
        } catch (Exception $e) {
            return $loggedAt;
        }

        $formatted = wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $local->getTimestamp()
        );

        // wp_date() is string|false. Keep the raw stored value when it fails,
        // same as the unparseable case above.
        return $formatted !== false ? $formatted : $loggedAt;
    }

    private function __construct()
    {
    }
}
