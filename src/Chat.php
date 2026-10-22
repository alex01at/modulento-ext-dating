<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Support\Clock;
use PDO;
use PDOException;

/**
 * The chat within one match. Read state reuses the core's own
 * message_seen table directly (scope='dating_match', ref_id=match_id,
 * sub_id=0 - a match is always exactly two people, so there is no second
 * axis to key on, unlike offer_message's per-visitor threads). The core's
 * MessageSeen class only knows the two scopes it ships with, so this talks
 * to the shared table itself rather than extending that class.
 */
final class Chat
{
    private const MAX_LENGTH = 2000;

    public function __construct(private PDO $db)
    {
    }

    /** Only onto an active match - an unmatched or not-yet-matched pair cannot exchange messages. */
    public function send(array $match, int $authorAccountId, string $body): ?int
    {
        if ($match['status'] !== 'active') {
            return null;
        }
        $body = mb_substr(trim($body), 0, self::MAX_LENGTH);
        if ($body === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_dating_message (match_id, author_account_id, body, created_at) VALUES (:match, :author, :body, :now)'
        );
        $stmt->execute(['match' => $match['id'], 'author' => $authorAccountId, 'body' => $body, 'now' => Clock::now()]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array> ordered oldest first */
    public function forMatch(int $matchId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_message WHERE match_id = :match ORDER BY id');
        $stmt->execute(['match' => $matchId]);

        return $stmt->fetchAll();
    }

    public function markSeen(int $accountId, int $matchId): void
    {
        $newest = $this->db->prepare('SELECT MAX(id) FROM x_dating_message WHERE match_id = :match');
        $newest->execute(['match' => $matchId]);
        $seenId = (int) $newest->fetchColumn();

        $update = $this->db->prepare(
            "UPDATE message_seen SET seen_id = :seen WHERE account_id = :account AND scope = 'dating_match' AND ref_id = :match AND sub_id = 0"
        );
        $update->execute(['seen' => $seenId, 'account' => $accountId, 'match' => $matchId]);
        if ($update->rowCount() > 0) {
            return;
        }

        try {
            $this->db->prepare(
                "INSERT INTO message_seen (account_id, scope, ref_id, sub_id, seen_id) VALUES (:account, 'dating_match', :match, 0, :seen)"
            )->execute(['account' => $accountId, 'match' => $matchId, 'seen' => $seenId]);
        } catch (PDOException) {
            // The same page opened twice at once: the other request was first.
        }
    }

    public function unreadInMatch(int $accountId, int $matchId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM x_dating_message m
             LEFT JOIN message_seen s ON s.account_id = :account1 AND s.scope = 'dating_match' AND s.ref_id = m.match_id AND s.sub_id = 0
             WHERE m.match_id = :match AND m.author_account_id <> :account2 AND m.id > COALESCE(s.seen_id, 0)"
        );
        $stmt->execute(['account1' => $accountId, 'match' => $matchId, 'account2' => $accountId]);

        return (int) $stmt->fetchColumn();
    }

    /** Across every active match of the account - for a "X new messages" badge. */
    public function unreadTotal(int $accountId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM x_dating_message m
             JOIN x_dating_match dm ON dm.id = m.match_id AND dm.status = 'active'
             LEFT JOIN message_seen s ON s.account_id = :account1 AND s.scope = 'dating_match' AND s.ref_id = m.match_id AND s.sub_id = 0
             WHERE (dm.account_a_id = :account2 OR dm.account_b_id = :account3)
               AND m.author_account_id <> :account4 AND m.id > COALESCE(s.seen_id, 0)"
        );
        $stmt->execute(['account1' => $accountId, 'account2' => $accountId, 'account3' => $accountId, 'account4' => $accountId]);

        return (int) $stmt->fetchColumn();
    }
}
