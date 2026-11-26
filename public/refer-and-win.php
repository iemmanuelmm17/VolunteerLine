<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VolunteerLine\EmailService;
use VolunteerLine\Helpers;
use VolunteerLine\Logger;
use VolunteerLine\ReferAndWinHandler;
use VolunteerLine\SessionStore;
use VolunteerLine\XmlResponse;

try {
    $config = volunteerline_load_config();
    $logger = new Logger((string) $config['log_dir'], (string) ($config['timezone'] ?? 'America/New_York'));
    $sessions = new SessionStore((string) $config['storage_dir']);
    $emailService = new EmailService($config, $logger);

    $handler = new ReferAndWinHandler($config, $sessions, $logger, $emailService);

    $params = [
        'step' => Helpers::requestParam('step', 'start'),
        'sid' => Helpers::requestParam('sid'),
        'Digits' => Helpers::requestParam('Digits'),
        'NmsAni' => Helpers::requestParam('NmsAni'),
        'OrigCallID' => Helpers::requestParam('OrigCallID'),
    ];

    $xml = $handler->handle($params);

    if (!headers_sent()) {
        header('Content-Type: text/xml; charset=UTF-8');
        header('Content-Length: ' . strlen($xml));
    }
    echo $xml;
} catch (\Throwable $e) {
    @error_log('Refer & Win error: ' . $e->getMessage());
    $fallback = new XmlResponse();
    $fallback->say('Sorry, a system error occurred. Goodbye.')->hangup()->send();
}
