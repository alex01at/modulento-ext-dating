<?php

declare(strict_types=1);

namespace Modulento\Dating;

/**
 * Turns a profile's postal code/city/country into coordinates for the
 * radius search in SwipeQueue. Never called in tests - they hand Profiles
 * their own stub implementation directly, so no real HTTP request is ever
 * made while testing.
 */
interface Geocoder
{
    /** @return array{lat: float, lng: float}|null null if the address could not be resolved */
    public function geocode(string $postalCode, string $city, string $country): ?array;
}
