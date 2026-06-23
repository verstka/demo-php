<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Settings;
use PDO;

final class Database
{
    private const SCHEMA_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS cms_users (
    user_email TEXT PRIMARY KEY NOT NULL,
    password_hash TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS articles (
    path TEXT PRIMARY KEY NOT NULL,
    material_id TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL DEFAULT '',
    html TEXT NOT NULL DEFAULT '',
    vms_json TEXT,
    is_visible INTEGER NOT NULL DEFAULT 1,
    og_title TEXT,
    og_description TEXT,
    og_image_relpath TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_articles_material_id ON articles(material_id);
SQL;

    public static function sqlitePath(Settings $settings): string
    {
        $url = $settings->databaseUrl;
        if (str_starts_with($url, 'sqlite:')) {
            $path = substr($url, strlen('sqlite:'));
            if ($path === '' || $path === ':memory:') {
                return $path;
            }
            if (!str_starts_with($path, '/')) {
                return $settings->projectRoot . '/' . ltrim($path, './');
            }

            return $path;
        }

        return $settings->projectRoot . '/data.db';
    }

    public static function connect(Settings $settings): PDO
    {
        $path = self::sqlitePath($settings);
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    public static function init(Settings $settings, ?PDO $pdo = null): PDO
    {
        $pdo ??= self::connect($settings);
        $pdo->exec(self::SCHEMA_SQL);
        self::migrateCmsUsersUsernameToUserEmail($pdo);

        if (!is_dir($settings->storageDir)) {
            mkdir($settings->storageDir, 0775, true);
        }
        if (!is_dir($settings->storageDir . '/fonts')) {
            mkdir($settings->storageDir . '/fonts', 0775, true);
        }

        return $pdo;
    }

    private static function migrateCmsUsersUsernameToUserEmail(PDO $pdo): void
    {
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(cms_users)') as $row) {
            $cols[(string) $row['name']] = true;
        }
        if (isset($cols['user_email']) || !isset($cols['username'])) {
            return;
        }
        $pdo->exec('ALTER TABLE cms_users RENAME COLUMN username TO user_email');
    }
}
