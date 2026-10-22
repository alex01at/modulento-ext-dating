<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * "Report this person" - deliberately its own table, not the core's
 * report table: that one is shaped for DMCA-style content takedowns
 * (illegal_goods, intellectual_property, ...), not for a match's
 * behaviour. Administration decides open reports; a decision does not by
 * itself block anyone - the operator does that by suspending the account
 * (account.status) the usual way, same as any other moderation action.
 */
final class Reports
{
    public const CATEGORIES = ['harassment', 'fake_profile', 'inappropriate_content', 'other'];

    public function __construct(private PDO $db)
    {
    }

    public function create(int $reporterAccountId, int $reportedAccountId, ?int $matchId, string $category, ?string $explanation): ?int
    {
        if ($reporterAccountId === $reportedAccountId || !in_array($category, self::CATEGORIES, true)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_dating_report (reporter_account_id, reported_account_id, match_id, category, explanation, status, created_at)
             VALUES (:reporter, :reported, :match, :category, :explanation, :status, :now)'
        );
        $stmt->execute([
            'reporter' => $reporterAccountId, 'reported' => $reportedAccountId, 'match' => $matchId,
            'category' => $category, 'explanation' => $explanation !== null ? mb_substr(trim($explanation), 0, 2000) : null,
            'status' => 'open', 'now' => Clock::now(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array> for the admin queue, newest first */
    public function listOpen(): array
    {
        $stmt = $this->db->query("SELECT * FROM x_dating_report WHERE status = 'open' ORDER BY created_at DESC");

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_report WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function decide(int $id, string $status, int $decidedBy): void
    {
        if (!in_array($status, ['actioned', 'rejected'], true)) {
            return;
        }

        $this->db->prepare('UPDATE x_dating_report SET status = :status, decided_at = :now, decided_by = :by WHERE id = :id')
            ->execute(['status' => $status, 'now' => Clock::now(), 'by' => $decidedBy, 'id' => $id]);
    }

    public function openCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM x_dating_report WHERE status = 'open'")->fetchColumn();
    }
}
