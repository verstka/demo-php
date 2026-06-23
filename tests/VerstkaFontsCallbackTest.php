<?php

declare(strict_types=1);

namespace Tests;

use App\Database\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Verstka\Sdk\Client\VerstkaClient;
use Verstka\Sdk\Config\VerstkaConfig;
use Verstka\Sdk\Signature\SignatureService;
use ZipArchive;

final class VerstkaFontsCallbackTest extends TestCase
{
    private string $tmpRoot;
    private \App\Config\Settings $settings;
    private string $zipPath;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/demo_php_' . uniqid('', true);
        mkdir($this->tmpRoot, 0775, true);
        $this->settings = TestSupport::makeTestSettings($this->tmpRoot);
        Database::init($this->settings);
        $this->zipPath = $this->tmpRoot . '/fonts.zip';
        $zip = new ZipArchive();
        $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('vms_fonts/Inter.woff', 'woff-data');
        $zip->addFromString('vms_fonts/Inter.woff2', 'woff2-data');
        $zip->addFromString(
            'vms_fonts.css',
            "@font-face{src:url(dummy-Inter.woff2) format('woff2'),url(dummy-Inter.woff) format('woff');}",
        );
        $zip->addFromString('vms_fonts.json', '{"families":["Inter"]}');
        $zip->close();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function testSiteFontsCallbackWithoutUserMetadataSavesFontFiles(): void
    {
        $materialId = 'site-fonts';
        $contentUrl = 'https://content.example.test/fonts.zip';
        $payload = [
            'event' => 'site_fonts_updated',
            'material_id' => $materialId,
            'content_url' => $contentUrl,
            'fonts' => [
                'css' => ['id' => 'vms_fonts.css'],
                'list' => [
                    [
                        'family' => 'Inter',
                        'variants' => [
                            [
                                'files' => [
                                    'woff' => ['id' => 'Inter.woff'],
                                    'woff2' => ['id' => 'Inter.woff2'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $signature = SignatureService::signMaterial($materialId, $contentUrl, $this->settings->verstkaApiSecret);
        $zipBytes = file_get_contents($this->zipPath);
        self::assertNotFalse($zipBytes);

        $mock = new MockHandler([new Response(200, [], $zipBytes)]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $verstkaClient = new VerstkaClient(
            new VerstkaConfig(
                apiKey: $this->settings->verstkaApiKey,
                apiSecret: $this->settings->verstkaApiSecret,
                callbackUrl: $this->settings->verstkaCallbackUrl,
                apiUrl: $this->settings->verstkaApiUrl,
                debug: true,
            ),
            $httpClient,
        );

        $app = TestSupport::buildCmsTestApp($this->settings, verstkaClient: $verstkaClient);
        $response = TestSupport::jsonRequest(
            $app,
            '/verstka/callback',
            $payload,
            ['X-Verstka-Signature' => $signature],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode(TestSupport::responseBody($response), true);
        self::assertSame(1, $body['rc'] ?? null, TestSupport::responseBody($response));

        $fontsDir = $this->settings->storageDir . '/fonts';
        self::assertSame('woff-data', file_get_contents($fontsDir . '/Inter.woff'));
        self::assertSame('woff2-data', file_get_contents($fontsDir . '/Inter.woff2'));
        self::assertFileExists($fontsDir . '/vms_fonts.json');
        self::assertFileExists($fontsDir . '/vms_fonts.css');
        self::assertFileExists($fontsDir . '/fonts.css');
        self::assertStringContainsString(
            'https://cms.example.test/fonts/Inter.woff2',
            (string) file_get_contents($fontsDir . '/fonts.css'),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
