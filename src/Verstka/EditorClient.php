<?php

declare(strict_types=1);

namespace App\Verstka;

interface EditorClient
{
    /** @param array<string, mixed>|string|null $vmsJson @param array<string, mixed>|string|null $metadata */
    public function getEditorUrl(
        string $materialId,
        array|string|null $vmsJson = null,
        array|string|null $metadata = null,
    ): string;
}
