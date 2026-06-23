<?php

declare(strict_types=1);

use App\AppFactory;
use App\Config\Settings;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
if (is_file($projectRoot . '/.env')) {
    Dotenv::createImmutable($projectRoot)->safeLoad();
}

$settings = Settings::fromEnv($projectRoot);
$app = AppFactory::create($settings);
$app->run();
