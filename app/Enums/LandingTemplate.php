<?php

namespace App\Enums;

/**
 * Which public home page a tenant shows (SLO-238, docs/25).
 *
 * `default` is the plain catalogue page every tenant has had. `calm` is the
 * warm, unhurried layout drawn for a solo practice — a therapist, a coach — and
 * the first template a tenant can choose rather than the only page there is.
 * `glam` is the dark, neon-pink salon page (SLO-241, docs/26): a team to choose
 * from, photo cards, and today's free times on the page itself.
 */
enum LandingTemplate: string
{
    case Default = 'default';
    case Calm = 'calm';
    case Glam = 'glam';
}
