<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use Scrutiny\Audit\AuditDetail;
use Scrutiny\Tests\TestCase;
use stdClass;

/**
 * Tests for AuditDetail, the Detail-column renderer shared by the Audit Log
 * admin page and the GdprAuditHistory field.
 *
 * Reach writes `caller:<name>#<id>` and `caller:<name>#<id>;result:<label>`
 * into the detail column. Everything else — legacy rows, entries from other
 * plugins, anything malformed — has to survive as plain escaped text rather
 * than disappearing or half-parsing, so the fallback path gets as much
 * attention here as the happy one.
 *
 * @covers \Scrutiny\Audit\AuditDetail
 */
class AuditDetailTest extends TestCase
{
    private function entry(string $action, string $detail): stdClass
    {
        $entry = new stdClass();
        $entry->action = $action;
        $entry->detail = $detail;

        return $entry;
    }

    // ──────────────────────────────────────────────
    //  Plain-text fallback
    // ──────────────────────────────────────────────

    /** @test */
    public function it_renders_other_actions_as_plain_text(): void
    {
        $this->assertSame(
            'Changed from x to y',
            AuditDetail::render($this->entry('update', 'Changed from x to y'))
        );
    }

    /** @test */
    public function it_escapes_the_plain_text_it_falls_back_to(): void
    {
        $html = AuditDetail::render($this->entry('update', '<script>alert(1)</script>'));

        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * @test
     * @dataProvider unparseableDetails
     */
    public function it_falls_back_when_the_caller_string_does_not_parse(string $detail): void
    {
        // A half-parsed caller string would put a wrong name against a member's
        // record, which is worse than showing the raw value.
        $this->assertSame($detail, AuditDetail::render($this->entry('view', $detail)));
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function unparseableDetails(): array
    {
        return [
            'no caller prefix'   => ['Viewed contact details'],
            'empty result label' => ['caller:John D.#7;result:'],
            'no hash at all'     => ['caller:John D.'],
            'empty name'         => ['caller:#7'],
            'non-numeric id'     => ['caller:John D.#abc'],
            'zero id'            => ['caller:John D.#0'],
        ];
    }

    // ──────────────────────────────────────────────
    //  Structured caller strings
    // ──────────────────────────────────────────────

    /** @test */
    public function it_labels_a_view_row_as_a_requester_and_links_them(): void
    {
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit-7');

        $html = AuditDetail::render($this->entry('view', 'caller:John D.#7'));

        $this->assertStringContainsString('Requester: ', $html);
        $this->assertStringContainsString('href="https://example.test/edit-7"', $html);
        $this->assertStringContainsString('>John D.</a>', $html);
    }

    /** @test */
    public function it_labels_a_call_row_as_a_caller_and_shows_the_result(): void
    {
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit-7');

        $html = AuditDetail::render($this->entry('call', 'caller:John D.#7;result:No answer'));

        $this->assertStringContainsString('Caller: ', $html);
        $this->assertStringContainsString('Result: No answer', $html);
    }

    /** @test */
    public function it_drops_the_link_when_the_caller_has_no_edit_screen(): void
    {
        // get_edit_post_link() returns null for a post the current user cannot
        // edit, or one that no longer exists. The name still has to render.
        Functions\when('get_edit_post_link')->justReturn(null);

        $html = AuditDetail::render($this->entry('view', 'caller:John D.#7'));

        $this->assertStringContainsString('Requester: John D.', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /** @test */
    public function it_renders_the_unknown_sentinel_without_a_link(): void
    {
        $html = AuditDetail::render($this->entry('view', 'caller:unknown'));

        $this->assertSame('Requester: unknown', $html);
    }

    /** @test */
    public function it_renders_the_unknown_sentinel_with_a_call_result(): void
    {
        $html = AuditDetail::render($this->entry('call', 'caller:unknown;result:Engaged'));

        $this->assertStringContainsString('Caller: unknown', $html);
        $this->assertStringContainsString('Result: Engaged', $html);
    }

    /** @test */
    public function it_splits_on_the_last_hash_so_names_containing_one_survive(): void
    {
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit-7');

        $html = AuditDetail::render($this->entry('view', 'caller:John #2 D.#7'));

        $this->assertStringContainsString('John #2 D.', $html);
        $this->assertStringContainsString('edit-7', $html);
    }

    /** @test */
    public function it_escapes_a_name_and_result_taken_from_the_detail_string(): void
    {
        Functions\when('get_edit_post_link')->justReturn(null);

        $html = AuditDetail::render(
            $this->entry('call', 'caller:<b>John</b>#7;result:<script>alert(1)</script>')
        );

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /** @test */
    public function it_tolerates_a_row_with_no_detail_or_action_at_all(): void
    {
        // $wpdb rows are NOT NULL in the schema, but the renderer is handed
        // whatever the caller has; an absent property must not fatal.
        $this->assertSame('', AuditDetail::render(new stdClass()));
    }
}
