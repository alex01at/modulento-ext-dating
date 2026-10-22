<?php

declare(strict_types=1);

namespace Modulento\Dating;

use DateTimeImmutable;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Countries;
use PDO;

/**
 * An account's own dating profile - one row, created the first time the
 * profile form is saved. Attached to account.id, never to provider.id:
 * tax id/legal form/business address are irrelevant here, and an account
 * does not need to be a provider in any other sense to date.
 */
final class Profiles
{
    public const GENDERS = ['female', 'male', 'other'];
    public const SEEKING = ['female', 'male', 'other', 'any'];
    public const MIN_AGE = 18;
    private const MAX_DISTANCE_KM = 300;

    public function __construct(private PDO $db, private Geocoder $geocoder)
    {
    }

    public function find(int $accountId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_profile WHERE account_id = :account_id');
        $stmt->execute(['account_id' => $accountId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_profile WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $input raw $_POST
     * @return array{values: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $input): array
    {
        $errors = [];

        $displayName = mb_substr(trim((string) ($input['display_name'] ?? '')), 0, 80);
        if ($displayName === '') {
            $errors[] = 'dating.profile.error.display_name';
        }

        $birthdate = (string) ($input['birthdate'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
        $age = null;
        if ($parsed === false || $parsed > new DateTimeImmutable('today')) {
            $errors[] = 'dating.profile.error.birthdate';
        } else {
            $age = (new DateTimeImmutable('today'))->diff($parsed)->y;
            if ($age < self::MIN_AGE) {
                $errors[] = 'dating.profile.error.too_young';
            }
        }

        $gender = (string) ($input['gender'] ?? '');
        if (!in_array($gender, self::GENDERS, true)) {
            $errors[] = 'dating.profile.error.gender';
        }

        $seeking = (string) ($input['seeking'] ?? 'any');
        if (!in_array($seeking, self::SEEKING, true)) {
            $errors[] = 'dating.profile.error.seeking';
        }

        $bio = mb_substr(trim((string) ($input['bio'] ?? '')), 0, 2000) ?: null;

        $postalCode = mb_substr(trim((string) ($input['postal_code'] ?? '')), 0, 20) ?: null;
        $city = mb_substr(trim((string) ($input['city'] ?? '')), 0, 100) ?: null;
        $country = mb_strtoupper(trim((string) ($input['country'] ?? '')));
        $country = $country === '' ? null : $country;
        if ($country !== null && !Countries::isValid($country)) {
            $errors[] = 'dating.profile.error.country';
        }

        $maxDistanceKm = (int) ($input['max_distance_km'] ?? 50);
        if ($maxDistanceKm < 1 || $maxDistanceKm > self::MAX_DISTANCE_KM) {
            $maxDistanceKm = min(max($maxDistanceKm, 1), self::MAX_DISTANCE_KM);
        }

        $status = (string) ($input['status'] ?? 'active') === 'paused' ? 'paused' : 'active';

        return [
            'values' => [
                'display_name' => $displayName,
                'birthdate' => $parsed !== false ? $parsed->format('Y-m-d') : null,
                'gender' => in_array($gender, self::GENDERS, true) ? $gender : null,
                'seeking' => in_array($seeking, self::SEEKING, true) ? $seeking : 'any',
                'bio' => $bio,
                'postal_code' => $postalCode,
                'city' => $city,
                'country' => $country,
                'max_distance_km' => $maxDistanceKm,
                'status' => $status,
            ],
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $values from validate()['values'] */
    public function save(int $accountId, array $values): void
    {
        $existing = $this->find($accountId);
        $addressChanged = $existing === null
            || $existing['postal_code'] !== $values['postal_code']
            || $existing['city'] !== $values['city']
            || $existing['country'] !== $values['country'];

        $lat = $existing['lat'] ?? null;
        $lng = $existing['lng'] ?? null;
        $geocodedAt = $existing['geocoded_at'] ?? null;
        if ($addressChanged) {
            $result = $values['postal_code'] !== null || $values['city'] !== null
                ? $this->geocoder->geocode((string) $values['postal_code'], (string) $values['city'], (string) $values['country'])
                : null;
            $lat = $result['lat'] ?? null;
            $lng = $result['lng'] ?? null;
            $geocodedAt = $result !== null ? Clock::now() : null;
        }

        $now = Clock::now();
        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO x_dating_profile
                    (account_id, display_name, birthdate, gender, seeking, bio, postal_code, city, country,
                     lat, lng, geocoded_at, max_distance_km, status, created_at, updated_at)
                 VALUES
                    (:account_id, :display_name, :birthdate, :gender, :seeking, :bio, :postal_code, :city, :country,
                     :lat, :lng, :geocoded_at, :max_distance_km, :status, :created_at, :updated_at)'
            );
            $stmt->execute($values + [
                'account_id' => $accountId, 'lat' => $lat, 'lng' => $lng, 'geocoded_at' => $geocodedAt,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE x_dating_profile SET
                display_name = :display_name, birthdate = :birthdate, gender = :gender, seeking = :seeking,
                bio = :bio, postal_code = :postal_code, city = :city, country = :country,
                lat = :lat, lng = :lng, geocoded_at = :geocoded_at, max_distance_km = :max_distance_km,
                status = :status, updated_at = :now
             WHERE account_id = :account_id'
        );
        $stmt->execute($values + [
            'account_id' => $accountId, 'lat' => $lat, 'lng' => $lng, 'geocoded_at' => $geocodedAt, 'now' => $now,
        ]);
    }
}
