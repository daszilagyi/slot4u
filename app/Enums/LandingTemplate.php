<?php

namespace App\Enums;

/**
 * Which public home page a tenant shows (SLO-238, docs/25).
 *
 * `default` is the plain catalogue page every tenant has had. `calm` is the
 * warm, unhurried layout drawn for a solo practice — a therapist, a coach — and
 * the first template a tenant can choose rather than the only page there is.
 */
enum LandingTemplate: string
{
    case Default = 'default';
    case Calm = 'calm';
}
