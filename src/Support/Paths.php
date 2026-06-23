<?php

declare(strict_types=1);

namespace App\Support;

final class Paths
{
    private const PATH_RE = '/^\/[a-zA-Z0-9\/_-]+$/';

    /** @var list<string> */
    private const RESERVED_PREFIXES = ['/cms', '/fonts'];

    /** @var list<string> */
    private const RESERVED_EXACT = ['', '/'];

    public static function normalizeArticlePath(string $path): string
    {
        $p = trim($path);
        if (!str_starts_with($p, '/')) {
            $p = '/' . $p;
        }
        $p = rtrim($p, '/') ?: '/';
        if ($p !== '/' && str_ends_with($p, '/')) {
            $p = rtrim($p, '/');
        }

        return $p;
    }

    public static function isValidArticlePath(string $path): bool
    {
        $n = self::normalizeArticlePath($path);
        if (in_array($n, self::RESERVED_EXACT, true)) {
            return false;
        }
        foreach (self::RESERVED_PREFIXES as $pref) {
            if ($n === $pref || str_starts_with($n, $pref . '/')) {
                return false;
            }
        }
        if (!preg_match(self::PATH_RE, $n)) {
            return false;
        }
        if (str_contains($n, '..') || str_contains($n, '//')) {
            return false;
        }

        return true;
    }

    public static function pathToStorageRelative(string $articlePath): string
    {
        $n = self::normalizeArticlePath($articlePath);
        if ($n === '/') {
            throw new \InvalidArgumentException('invalid path');
        }
        $rel = ltrim($n, '/');
        foreach (explode('/', $rel) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '..')) {
                throw new \InvalidArgumentException('unsafe path segment');
            }
        }

        return $rel;
    }

    public static function storageArticleDir(string $storageRoot, string $articlePath): string
    {
        $rel = self::pathToStorageRelative($articlePath);

        return $storageRoot . '/' . $rel;
    }
}
