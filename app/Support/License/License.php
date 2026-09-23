<?php

namespace App\Support\License;

use Illuminate\Support\Carbon;

/**
 * Una licencia ya verificada: para quién es y hasta cuándo vale.
 *
 * La vigencia es por día completo: una licencia que expira el día X vale hasta
 * el final de ese día.
 */
final readonly class License
{
    public function __construct(
        public string $licensee,
        public Carbon $issuedAt,
        public Carbon $expiresAt,
        public ?string $notes = null,
    ) {}

    public function isExpired(?Carbon $at = null): bool
    {
        return $this->expiresAt->startOfDay()->lt(($at ?? Carbon::now())->startOfDay());
    }

    public function daysLeft(?Carbon $at = null): int
    {
        return (int) ($at ?? Carbon::now())->startOfDay()->diffInDays($this->expiresAt->startOfDay(), false);
    }
}
