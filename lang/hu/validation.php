<?php

/*
 * Hungarian validation messages. Only the rules currently exercised by the app
 * (auth + reset forms) are translated here; any rule not listed falls back to
 * the framework's English defaults (APP_FALLBACK_LOCALE=en). SLO-9 completes the
 * full set.
 */

return [
    'confirmed' => 'A megadott :attribute nem egyezik a megerősítéssel.',
    'current_password' => 'A megadott jelszó hibás.',
    'email' => 'A(z) :attribute érvényes email cím kell legyen.',
    'max' => [
        'string' => 'A(z) :attribute nem lehet hosszabb :max karakternél.',
    ],
    'min' => [
        'string' => 'A(z) :attribute legalább :min karakter kell legyen.',
    ],
    'required' => 'A(z) :attribute megadása kötelező.',
    'string' => 'A(z) :attribute szöveg kell legyen.',
    'unique' => 'Ez a(z) :attribute már foglalt.',

    /*
     * Custom per-field messages.
     */
    'custom' => [
        'slug' => [
            'reserved' => 'Ez az aldomén foglalt, kérjük válassz másikat.',
            'regex' => 'Az aldomén csak kisbetűket, számokat és kötőjelet tartalmazhat.',
        ],
    ],

    /*
     * Human-readable attribute names substituted into the messages above.
     */
    'attributes' => [
        'email' => 'email cím',
        'password' => 'jelszó',
        'name' => 'név',
        'token' => 'token',
        'company_name' => 'cégnév',
        'slug' => 'aldomén',
    ],
];
