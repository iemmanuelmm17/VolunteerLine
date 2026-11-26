<?php

declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/SessionStore.php';
require_once __DIR__ . '/XmlResponse.php';
require_once __DIR__ . '/WavConverter.php';
require_once __DIR__ . '/SmtpClient.php';
require_once __DIR__ . '/EmailServiceInterface.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/CallAndWinHandler.php';
require_once __DIR__ . '/ReferAndWinHandler.php';
require_once __DIR__ . '/WebSocketServer.php';

/**
 * Load application configuration.
 *
 * @return array<string, mixed>
 */
function volunteerline_load_config(?string $configPath = null): array
{
    $path = $configPath ?? (__DIR__ . '/../config/config.php');
    if (!is_file($path)) {
        throw new RuntimeException('Configuration file missing. Copy config/config.example.php to config/config.php.');
    }

    /** @var mixed $loaded */
    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('Configuration file must return an array.');
    }

    $timezone = (string) ($loaded['timezone'] ?? 'America/New_York');
    date_default_timezone_set($timezone);

    return $loaded;
}
