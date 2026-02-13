<?php
declare(strict_types=1);

namespace App\Support;

class Config
{
    public const MATCH_AUTO_THRESHOLD = 95;
    public const MATCH_REVIEW_THRESHOLD = 0.70;

    // أوزان المصادر
    public const WEIGHT_OFFICIAL = 1.0;
    public const WEIGHT_ALT_CONFIRMED = 0.85;
    public const WEIGHT_ALT_LEARNING = 0.75;
    public const WEIGHT_FUZZY = 0.6;

    public const CONFLICT_DELTA = 0.1;
}
