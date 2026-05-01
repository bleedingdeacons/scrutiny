<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\PrunerSettings;
use Unity\Members\Interfaces\MemberRepository;

/**
 * MemberPruner subclass for testing.
 *
 * Records IDs passed to trashMember() and short-circuits the
 * wp_trash_post call. Lets the suite assert exactly which members
 * would be trashed without needing a WordPress runtime.
 *
 * Lives in its own file so both MemberPrunerTest and
 * MemberPrunerLoggingTest can use it without one having to
 * include the other.
 */
final class MemberPrunerForTest extends MemberPruner
{
    /** @var array<int> */
    private array $trashed = [];

    public function __construct(
        MemberRepository $members,
        DateTimeImmutable $now,
        private bool $trashSucceeds,
        ?PrunerSettings $settings = null
    ) {
        parent::__construct($members, $now, $settings);
    }

    /** @return array<int> */
    public function getTrashedIds(): array
    {
        return $this->trashed;
    }

    protected function trashMember(int $memberId): bool
    {
        if (!$this->trashSucceeds) {
            return false;
        }
        $this->trashed[] = $memberId;
        return true;
    }
}
