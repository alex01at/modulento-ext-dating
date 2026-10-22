<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * The swipe action (x_dating_swipe) and what comes of it: a match the
 * moment both sides have liked each other (x_dating_match). A pair's match
 * row is keyed by the smaller account id first, so it stays unique no
 * matter who liked whom first.
 */
final class Matches
{
    /** Likes per day without dating.feature.unlimited_likes. */
    public const DAILY_FREE_LIKES = 20;

    public function __construct(private PDO $db)
    {
    }

    /** Records a like; returns the new match's id if this completes one, else null. */
    public function like(int $accountId, int $targetAccountId): ?int
    {
        $this->swipe($accountId, $targetAccountId, 'like');

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM x_dating_swipe WHERE liker_account_id = :target AND target_account_id = :account AND direction = 'like'"
        );
        $stmt->execute(['target' => $targetAccountId, 'account' => $accountId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return null;
        }

        return $this->createMatch($accountId, $targetAccountId);
    }

    public function pass(int $accountId, int $targetAccountId): void
    {
        $this->swipe($accountId, $targetAccountId, 'pass');
    }

    /** Takes back the account's last pass, so that profile reappears in its own queue. Gated by dating.feature.rewind. */
    public function rewind(int $accountId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT target_account_id FROM x_dating_swipe WHERE liker_account_id = :account AND direction = 'pass' ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['account' => $accountId]);
        $targetId = $stmt->fetchColumn();
        if ($targetId === false) {
            return false;
        }

        $this->db->prepare('DELETE FROM x_dating_swipe WHERE liker_account_id = :account AND target_account_id = :target')
            ->execute(['account' => $accountId, 'target' => $targetId]);

        return true;
    }

    /** Likes received by the account, without a reverse like (yet) - "who liked you". */
    public function likedByCount(int $accountId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM x_dating_swipe s
             WHERE s.target_account_id = :account AND s.direction = 'like'
               AND NOT EXISTS (
                   SELECT 1 FROM x_dating_swipe r
                   WHERE r.liker_account_id = :account2 AND r.target_account_id = s.liker_account_id
               )"
        );
        $stmt->execute(['account' => $accountId, 'account2' => $accountId]);

        return (int) $stmt->fetchColumn();
    }

    /** How many likes the account has given today - the free-tier daily limit counts this. */
    public function likesGivenToday(int $accountId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM x_dating_swipe
             WHERE liker_account_id = :account AND direction = 'like' AND created_at >= :since"
        );
        $stmt->execute(['account' => $accountId, 'since' => substr(Clock::now(), 0, 10) . ' 00:00:00']);

        return (int) $stmt->fetchColumn();
    }

    public function find(int $matchId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_match WHERE id = :id');
        $stmt->execute(['id' => $matchId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** The other account of a match, for an account that is part of it - null if it is not. */
    public function otherAccountId(array $match, int $accountId): ?int
    {
        if ((int) $match['account_a_id'] === $accountId) {
            return (int) $match['account_b_id'];
        }
        if ((int) $match['account_b_id'] === $accountId) {
            return (int) $match['account_a_id'];
        }

        return null;
    }

    /** @return list<array> active matches of an account, newest first */
    public function forAccount(int $accountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM x_dating_match
             WHERE status = 'active' AND (account_a_id = :account1 OR account_b_id = :account2)
             ORDER BY created_at DESC"
        );
        $stmt->execute(['account1' => $accountId, 'account2' => $accountId]);

        return $stmt->fetchAll();
    }

    public function unmatch(int $matchId): void
    {
        $this->db->prepare("UPDATE x_dating_match SET status = 'unmatched', unmatched_at = :now WHERE id = :id")
            ->execute(['now' => Clock::now(), 'id' => $matchId]);
    }

    private function swipe(int $accountId, int $targetAccountId, string $direction): void
    {
        if ($accountId === $targetAccountId) {
            return;
        }

        $update = $this->db->prepare(
            'UPDATE x_dating_swipe SET direction = :direction, created_at = :now WHERE liker_account_id = :account AND target_account_id = :target'
        );
        $update->execute(['direction' => $direction, 'now' => Clock::now(), 'account' => $accountId, 'target' => $targetAccountId]);
        if ($update->rowCount() > 0) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO x_dating_swipe (liker_account_id, target_account_id, direction, created_at) VALUES (:account, :target, :direction, :now)'
        )->execute(['account' => $accountId, 'target' => $targetAccountId, 'direction' => $direction, 'now' => Clock::now()]);
    }

    private function createMatch(int $accountId, int $targetAccountId): int
    {
        $a = min($accountId, $targetAccountId);
        $b = max($accountId, $targetAccountId);

        $existing = $this->db->prepare('SELECT id FROM x_dating_match WHERE account_a_id = :a AND account_b_id = :b');
        $existing->execute(['a' => $a, 'b' => $b]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            // Matching again after an unmatch: reactivate the same row rather than doubling it.
            $this->db->prepare("UPDATE x_dating_match SET status = 'active', unmatched_at = NULL WHERE id = :id")->execute(['id' => $id]);

            return (int) $id;
        }

        $this->db->prepare(
            'INSERT INTO x_dating_match (account_a_id, account_b_id, status, created_at) VALUES (:a, :b, :status, :now)'
        )->execute(['a' => $a, 'b' => $b, 'status' => 'active', 'now' => Clock::now()]);

        return (int) $this->db->lastInsertId();
    }
}
