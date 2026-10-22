<?php

declare(strict_types=1);

namespace Modulento\Dating;

/**
 * The real Geocoder, against OpenStreetMap's Nominatim (no API key, but a
 * descriptive User-Agent is required by its usage policy and a result is
 * never guaranteed - a profile simply stays without coordinates, and so
 * out of the radius search, until it resolves or the address is corrected).
 */
final class HttpGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    public function __construct(private string $userAgent)
    {
    }

    public function geocode(string $postalCode, string $city, string $country): ?array
    {
        if ($city === '' && $postalCode === '') {
            return null;
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'format' => 'json',
            'limit' => 1,
            'postalcode' => $postalCode,
            'city' => $city,
            'country' => $country,
        ]);

        $context = stream_context_create([
            'http' => ['header' => "User-Agent: {$this->userAgent}\r\n", 'timeout' => 5, 'ignore_errors' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return ['lat' => (float) $data[0]['lat'], 'lng' => (float) $data[0]['lon']];
    }
}
