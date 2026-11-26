<?php

declare(strict_types=1);

namespace VolunteerLine;

interface EmailServiceInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function sendCallAndWin(array $data): void;

    /**
     * @param array<string, mixed> $data
     */
    public function sendReferAndWin(array $data, ?string $wavPath = null): void;
}
