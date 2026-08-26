<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLogger;
use function esc_html;
use function esc_url;
use function get_edit_post_link;

/**
 * Audit detail rendering.
 *
 * Reach writes structured detail strings for both the view step (when contact
 * details are exposed in a nearest-members lookup) and the call step (when the
 * visitor logs a phone-call result):
 *
 *   View: `caller:<name>#<id>`          (or `caller:unknown`)
 *   Call: `caller:<name>#<id>;result:<label>`
 *                                       (or `caller:unknown;result:<label>`)
 *
 * Turning those into readable cells is shared between the Audit Log admin page
 * and the GdprAuditHistory ACF field, so it lives here rather than on either
 * surface.
 */
final class AuditDetail
{
    /**
     * Render the Detail column for a single audit row.
     *
     * View rows render as a linked "Requester: <name>"; call rows render as
     * "Caller: <name>   Result: <label>". The link points to the
     * requester/caller's *own* member edit page (the row's entity_id already
     * points to the member who was viewed / called, and is linked separately
     * by the caller).
     *
     * Any other shape, or any other action, renders as escaped plain text —
     * preserving legacy rows and audit entries from other plugins or earlier
     * versions.
     *
     * @param object $entry An audit log row.
     * @return string HTML, already escaped.
     */
    public static function render(object $entry): string
    {
        $detail = (string) ($entry->detail ?? '');
        $action = (string) ($entry->action ?? '');

        if (
            $action !== AuditLogger::ACTION_CALL
            && $action !== AuditLogger::ACTION_VIEW
        ) {
            return esc_html($detail);
        }

        $parts = self::parseCallerDetail($detail);
        if ($parts === null) {
            return esc_html($detail);
        }

        [$callerName, $callerId, $result] = $parts;

        // Caller fragment — link the name when we have a usable id.
        if ($callerId !== null) {
            $editUrl = get_edit_post_link($callerId);
            $callerHtml = $editUrl
                ? sprintf('<a href="%s">%s</a>', esc_url($editUrl), esc_html($callerName))
                : esc_html($callerName);
        } else {
            $callerHtml = esc_html($callerName);
        }

        // The same structured detail format is used for both view and call
        // rows, but the human label changes: a view exposes contact data to a
        // "requester" running a search, while a call attempt is logged by the
        // "caller" who placed the call.
        $personLabel = $action === AuditLogger::ACTION_CALL ? 'Caller' : 'Requester';

        if ($result === null) {
            return sprintf('%s: %s', $personLabel, $callerHtml);
        }

        return sprintf(
            '%s: %s &nbsp; Result: %s',
            $personLabel,
            $callerHtml,
            esc_html($result),
        );
    }

    /**
     * Parse a Reach audit-detail string into [name, id|null, result|null].
     *
     * Accepts either `caller:<name>#<id>` (view rows) or
     * `caller:<name>#<id>;result:<label>` (call rows), with the `unknown`
     * sentinel allowed in place of `<name>#<id>` in both cases. Returns null
     * when the string doesn't match, so the caller can fall back to plain-text
     * rendering.
     *
     * @param string $detail The raw `detail` column value.
     * @return array{0:string,1:?int,2:?string}|null
     */
    public static function parseCallerDetail(string $detail): ?array
    {
        if (!str_starts_with($detail, 'caller:')) {
            return null;
        }

        $payload = substr($detail, strlen('caller:'));

        // Optional `;result:<label>` suffix.
        $result    = null;
        $semicolon = strpos($payload, ';result:');
        if ($semicolon !== false) {
            $result = substr($payload, $semicolon + strlen(';result:'));
            if ($result === '') {
                return null;
            }
            $payload = substr($payload, 0, $semicolon);
        }

        if ($payload === 'unknown') {
            return ['unknown', null, $result];
        }

        // Split on the *last* '#' so anonymous names containing '#'
        // (unusual but possible) don't poison the parse.
        $hashPos = strrpos($payload, '#');
        if ($hashPos === false) {
            return null;
        }

        $name   = substr($payload, 0, $hashPos);
        $idPart = substr($payload, $hashPos + 1);

        if ($name === '' || !ctype_digit($idPart) || (int) $idPart <= 0) {
            return null;
        }

        return [$name, (int) $idPart, $result];
    }

    private function __construct()
    {
    }
}
