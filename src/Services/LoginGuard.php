<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;

/** In-memory brute-force protection for CMS login (per user email). */
final class LoginGuard
{
    /** @var array<string, list<float>> */
    private static array $failures = [];

    public static function resetLoginFailures(): void
    {
        self::$failures = [];
    }

    public static function isUserLoginBlocked(Settings $settings, string $userEmail): bool
    {
        $now = microtime(true);
        $count = self::failureCount($userEmail, $settings->cmsLoginWindowSeconds, $now);

        return $count >= $settings->cmsLoginMaxFailures;
    }

    public static function recordUserLoginFailure(Settings $settings, string $userEmail): void
    {
        $now = microtime(true);
        $window = $settings->cmsLoginWindowSeconds;
        $timestamps = self::prune(self::$failures[$userEmail] ?? [], $window, $now);
        $timestamps[] = $now;
        self::$failures[$userEmail] = $timestamps;
    }

    public static function clearUserLoginFailures(string $userEmail): void
    {
        unset(self::$failures[$userEmail]);
    }

    /** @param list<float> $timestamps */
    private static function prune(array $timestamps, int $windowSeconds, float $now): array
    {
        $cutoff = $now - $windowSeconds;

        return array_values(array_filter($timestamps, static fn (float $t): bool => $t >= $cutoff));
    }

    private static function failureCount(string $userEmail, int $windowSeconds, float $now): int
    {
        $timestamps = self::prune(self::$failures[$userEmail] ?? [], $windowSeconds, $now);
        self::$failures[$userEmail] = $timestamps;

        return count($timestamps);
    }
}
