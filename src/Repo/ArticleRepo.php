<?php

declare(strict_types=1);

namespace App\Repo;

use App\Support\Paths;
use PDO;

final class ArticleRepo
{
    private const VISIBLE_ARTICLES_WHERE = "
WHERE is_visible = 1
  AND path NOT IN ('/menu', '/footer')
ORDER BY path
";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listCmsUsers(): array
    {
        $rows = $this->pdo->query('SELECT user_email FROM cms_users ORDER BY user_email')->fetchAll();

        return array_map(static fn (array $r): array => ['user_email' => $r['user_email']], $rows);
    }

    public function countCmsUsers(): int
    {
        $row = $this->pdo->query('SELECT COUNT(*) AS c FROM cms_users')->fetch();

        return (int) ($row['c'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function getCmsUser(string $userEmail): ?array
    {
        $stmt = $this->pdo->prepare('SELECT user_email, password_hash FROM cms_users WHERE user_email = ?');
        $stmt->execute([$userEmail]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function insertCmsUser(string $userEmail, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_users (user_email, password_hash) VALUES (?, ?)');
        $stmt->execute([$userEmail, $passwordHash]);
    }

    public function deleteCmsUser(string $userEmail): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_users WHERE user_email = ?');
        $stmt->execute([$userEmail]);
    }

    public function updateCmsUserPassword(string $userEmail, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_users SET password_hash = ? WHERE user_email = ?');
        $stmt->execute([$passwordHash, $userEmail]);
    }

    /** @return array<string, mixed>|null */
    public function articleByPath(string $path): ?array
    {
        $p = Paths::normalizeArticlePath($path);
        $stmt = $this->pdo->prepare('SELECT * FROM articles WHERE path = ?');
        $stmt->execute([$p]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function articleByMaterialId(string $materialId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM articles WHERE material_id = ?');
        $stmt->execute([$materialId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listArticles(): array
    {
        return $this->pdo->query('SELECT * FROM articles ORDER BY path')->fetchAll();
    }

    /** @return list<array<string, mixed>|string> */
    public function listVisibleArticles(bool $pathsOnly = false): array
    {
        $select = $pathsOnly ? 'SELECT path FROM articles' : 'SELECT * FROM articles';
        $rows = $this->pdo->query($select . self::VISIBLE_ARTICLES_WHERE)->fetchAll();
        if ($pathsOnly) {
            return array_column($rows, 'path');
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function insertArticle(
        string $path,
        string $title,
        ?string $ogTitle,
        ?string $ogDescription,
        ?string $ogImageRelpath,
        bool $isVisible = true,
    ): array {
        $p = Paths::normalizeArticlePath($path);
        if (!Paths::isValidArticlePath($p)) {
            throw new \InvalidArgumentException('invalid or reserved path');
        }
        $materialId = self::uuid4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO articles (
                path, material_id, title, html, vms_json, is_visible,
                og_title, og_description, og_image_relpath
            ) VALUES (?, ?, ?, \'\', NULL, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $p,
            $materialId,
            $title,
            $isVisible ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageRelpath,
        ]);

        return $this->articleByPath($p) ?? [];
    }

    /** @return array<string, mixed> */
    public function updateArticleMeta(
        string $path,
        ?string $title = null,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?string $ogImageRelpath = null,
        ?bool $isVisible = null,
    ): array {
        $p = Paths::normalizeArticlePath($path);
        $row = $this->articleByPath($p);
        if (!$row) {
            throw new \RuntimeException('article not found');
        }
        $fields = [];
        $vals = [];
        if ($title !== null) {
            $fields[] = 'title = ?';
            $vals[] = $title;
        }
        if ($ogTitle !== null) {
            $fields[] = 'og_title = ?';
            $vals[] = $ogTitle;
        }
        if ($ogDescription !== null) {
            $fields[] = 'og_description = ?';
            $vals[] = $ogDescription;
        }
        if ($ogImageRelpath !== null) {
            $fields[] = 'og_image_relpath = ?';
            $vals[] = $ogImageRelpath;
        }
        if ($isVisible !== null) {
            $fields[] = 'is_visible = ?';
            $vals[] = $isVisible ? 1 : 0;
        }
        if ($fields === []) {
            return $row;
        }
        $fields[] = "updated_at = datetime('now')";
        $vals[] = $p;
        $stmt = $this->pdo->prepare('UPDATE articles SET ' . implode(', ', $fields) . ' WHERE path = ?');
        $stmt->execute($vals);

        return $this->articleByPath($p) ?? [];
    }

    public function updateArticleFromVerstka(string $materialId, string $html, ?string $vmsJson): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE articles SET html = ?, vms_json = ?, updated_at = datetime('now') WHERE material_id = ?",
        );
        $stmt->execute([$html, $vmsJson, $materialId]);
    }

    public function deleteArticle(string $path): void
    {
        $p = Paths::normalizeArticlePath($path);
        $stmt = $this->pdo->prepare('DELETE FROM articles WHERE path = ?');
        $stmt->execute([$p]);
    }

    /** @return array<string, mixed>|null */
    public function parseVmsJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
