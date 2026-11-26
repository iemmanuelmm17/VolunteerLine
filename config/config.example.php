<?php

declare(strict_types=1);

/**
 * VolunteerLine Configuration Sample
 *
 * Copy this file to config/config.php and update the values for your environment.
 * Never commit config/config.php or .env to Git.
 */

return [
    // Public base URL where NetSapiens can reach the HTTP endpoints (no trailing slash).
    // Example if DocumentRoot is d:/www/volunteerline/public: https://voice.example.org
    // Example if mounted under a subfolder: https://voice.example.org/volunteerline/public
    'app_base_url' => 'https://voice.example.org',

    // Public WebSocket URL for NetSapiens <Stream> recording (must be wss:// in production).
    // Reverse-proxied by Apache (mod_proxy_wstunnel) to ws://{ws_bind_host}:{ws_bind_port}.
    'ws_public_url' => 'wss://voice.example.org/ws',

    // Local bind address and port for bin/ws-server.php
    'ws_bind_host' => '127.0.0.1',
    'ws_bind_port' => 8090,

    // Timezone for email timestamps and logs (Eastern Time)
    'timezone' => 'America/New_York',

    // NetSapiens TTS (<Say>) defaults
    'tts_language' => 'en-US',
    'tts_voice' => 'male',

    // DTMF input settings
    'team_number_max_digits' => 10,
    'call_count_max_digits' => 10,
    'friend_phone_digits' => 10,
    'gather_timeout_seconds' => 10,
    'gather_digit_end_timeout_seconds' => 3,

    // Number of invalid/timeout attempts before hanging up
    'max_retries' => 3,

    // Safety limit on how many times a caller can press 2 to re-enter/re-record a step
    'max_reentries' => 10,

    // Refer & Win voice recording settings
    'max_recording_seconds' => 60,
    'refer_confirm_before_send' => true,   // true = play back recording & ask 1=confirm / 2=re-record
    'discard_on_early_hangup' => true,     // true = discard email if caller hangs up before completing/confirming
    'keep_recordings' => true,             // true = keep .wav files on disk after sending email
    'ws_stream_track' => 'inbound',        // 'inbound', 'outbound', 'both', or '' to omit track attribute
    'beep_url' => '',                      // Optional custom beep .wav URL; defaults to {app_base_url}/beep.wav

    // Email recipients (separate lists for each option; accepts string or array of strings)
    'call_and_win_email_to' => [
        'callandwin@example.org',
    ],
    'refer_and_win_email_to' => [
        'referandwin@example.org',
    ],

    // SMTP configuration (STARTTLS)
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'starttls',       // 'starttls', 'tls', or 'none'
    'smtp_username' => 'volunteerline@example.org',
    'smtp_password' => 'CHANGE_ME',
    'smtp_from_email' => 'volunteerline@example.org',
    'smtp_from_name' => 'Volunteer Line',
    'smtp_timeout' => 20,
    'smtp_verify_peer' => true,

    // Secret used to sign temporary <Play> recording URLs (change to a random string)
    'recording_access_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',

    // Storage and logging directories
    'log_dir' => __DIR__ . '/../logs',
    'storage_dir' => __DIR__ . '/../storage',

    // Optional prompt overrides (plain text for <Say>)
    'prompts' => [
        'call_and_win_welcome' => 'Welcome to Call and Win. Please enter your team number, up to {max_digits} digits.',
        'refer_and_win_welcome' => 'Welcome to Refer and Win. Please enter your team number, up to {max_digits} digits.',
        'enter_call_count' => 'Please enter the number of calls you made.',
        'enter_friend_phone' => 'Please enter your friend\'s 10 digit phone number.',
        'record_instructions' => 'Please leave a short voice message with your friend\'s name and address after the tone. Press pound when you are finished.',
    ],
];
