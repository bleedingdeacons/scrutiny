<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * In-memory MemberRepository for the Cleanup test suite.
 *
 * Returns the array of Member instances passed at construction. Only
 * findAll() (and findById() / count() for completeness) is used by
 * the pruner; the mutation methods throw so a future change to the
 * pruner that accidentally started writing through this fake fails
 * loudly instead of silently appearing to succeed.
 *
 * Lives in its own file rather than at the bottom of a test file
 * so it can be shared between MemberPrunerTest and the logging
 * tests without duplication or load-order coupling.
 */
final class InMemoryMemberRepository implements MemberRepository
{
    /** @param array<Member> $members */
    public function __construct(private array $members) {}

    public function findById(int $id): ?Member
    {
        foreach ($this->members as $member) {
            if ($member->getId() === $id) {
                return $member;
            }
        }
        return null;
    }

    public function findByEmail(string $email): ?Member
    {
        foreach ($this->members as $member) {
            if ($member->getPersonalEmail() === $email) {
                return $member;
            }
        }
        return null;
    }

    public function findAll(array $args = []): array
    {
        return $this->members;
    }

    public function findTelephoneResponders(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (Member $member): bool => $member->isTelephoneResponder()
        ));
    }

    public function count(array $args = []): int
    {
        return count($this->members);
    }

    public function create(string $anonymousName): int
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function save(Member $member): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function delete(int $id): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function update(Member $member): bool
    {
        throw new \LogicException('Not implemented in test double');
    }
}
