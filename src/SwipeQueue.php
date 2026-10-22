<?php

declare(strict_types=1);

namespace Modulento\Dating;

use PDO;

/**
 * The next profile to show a swiping account: not itself, not already
 * swiped (either direction), not blocked in either direction, matching
 * gender/seeking in both directions, and within the viewer's own
 * max_distance_km - skipped when either side has no coordinates yet, since
 * then distance simply cannot be judged (a freshly created profile should
 * still be discoverable before its address resolves).
 *
 * Distance itself is computed in PHP (haversine), not in SQL, so the same
 * logic works unchanged against the SQLite fixture in tests and the real
 * MariaDB in production - a batch of profiles that already pass the cheap
 * SQL filters is fetched, then checked and the first match returned.
 */
final class SwipeQueue
{
    private const BATCH = 50;

    public function __construct(private PDO $db, private Blocks $blocks)
    {
    }

    public function next(int $accountId, array $viewerProfile): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.* FROM x_dating_profile p
             WHERE p.account_id <> :account
               AND p.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM x_dating_swipe s
                   WHERE s.liker_account_id = :account2 AND s.target_account_id = p.account_id
               )
               AND (:seeking1 = 'any' OR p.gender = :seeking2)
               AND (p.seeking = 'any' OR p.seeking = :gender)
             ORDER BY p.id
             LIMIT :limit"
        );
        $stmt->bindValue('account', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('account2', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('seeking1', $viewerProfile['seeking']);
        $stmt->bindValue('seeking2', $viewerProfile['seeking']);
        $stmt->bindValue('gender', $viewerProfile['gender']);
        $stmt->bindValue('limit', self::BATCH, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $candidate) {
            if ($this->blocks->eitherWay($accountId, (int) $candidate['account_id'])) {
                continue;
            }
            if ($this->withinDistance($viewerProfile, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function withinDistance(array $viewer, array $candidate): bool
    {
        if ($viewer['lat'] === null || $viewer['lng'] === null || $candidate['lat'] === null || $candidate['lng'] === null) {
            return true;
        }

        return self::haversineKm((float) $viewer['lat'], (float) $viewer['lng'], (float) $candidate['lat'], (float) $candidate['lng'])
            <= (int) $viewer['max_distance_km'];
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
