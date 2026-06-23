<?php

declare(strict_types=1);

namespace App\Verstka;

use Verstka\Sdk\Client\VerstkaClient;

final class VerstkaClientAdapter implements EditorClient
{
    public function __construct(private readonly VerstkaClient $client)
    {
    }

    public function getEditorUrl(
        string $materialId,
        array|string|null $vmsJson = null,
        array|string|null $metadata = null,
    ): string {
        return $this->client->getEditorUrl($materialId, $vmsJson, $metadata);
    }
}
