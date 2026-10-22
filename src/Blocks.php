<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * A peer-to-peer block: a single account's own decision against another,
 * never an administrator's. Deliberately separate from account.status
 * (the admin-side account suspension) - that is a moderation action, this
 * is a user protecting themselves, and it is checked everywhere the other
 * account could otherwise reach them (swipe queue, chat).
 */
final class Blocks
{
    public function __construct(private PDO $db)
    {
    }

    public function block(int $blockerAccountId, int $blockedAccountId): void
    {
        if ($blockerAccountId === $blockedAccountId || $this->blocked($blockerAccountId, $blockedAccountId)) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO x_dating_block (blocker_account_id, blocked_account_id, created_at) VALUES (:blocker, :blocked, :now)'
        )->execute(['blocker' => $blockerAccountId, 'blocked' => $blockedAccountId, 'now' => Clock::now()]);
    }

    public function unblock(int $blockerAccountId, int $blockedAccountId): void
    {
        $this->db->prepare('DELETE FROM x_dating_block WHERE blocker_account_id = :blocker AND blocked_account_id = :blocked')
            ->execute(['blocker' => $blockerAccountId, 'blocked' => $blockedAccountId]);
    }

    public function blocked(int $blockerAccountId, int $blockedAccountId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM x_dating_block WHERE blocker_account_id = :blocker AND blocked_account_id = :blocked'
        );
        $stmt->execute(['blocker' => $blockerAccountId, 'blocked' => $blockedAccountId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Whether either account has blocked the other - the check that matters for queue/chat. */
    public function eitherWay(int $accountIdA, int $accountIdB): bool
    {
        return $this->blocked($accountIdA, $accountIdB) || $this->blocked($accountIdB, $accountIdA);
    }

    /** @return list<int> accounts this account has blocked, for its own settings page */
    public function blockedByAccount(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT blocked_account_id FROM x_dating_block WHERE blocker_account_id = :blocker ORDER BY created_at DESC');
        $stmt->execute(['blocker' => $accountId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
