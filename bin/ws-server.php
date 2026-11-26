<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VolunteerLine\EmailService;
use VolunteerLine\Logger;
use VolunteerLine\SessionStore;
use VolunteerLine\WebSocketServer;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

set_time_limit(0);

$config = volunteerline_load_config();
$logger = new Logger((string) $config['log_dir'], (string) ($config['timezone'] ?? 'America/New_York'));
$sessions = new SessionStore((string) $config['storage_dir']);
$emailService = new EmailService($config, $logger);

$server = new WebSocketServer($config, $sessions, $logger, $emailService);

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use ($server): void {
        $server->stop();
    });
    pcntl_signal(SIGTERM, static function () use ($server): void {
        $server->stop();
    });
}

$server->run();
