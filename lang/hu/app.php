<?php

return [
    'welcome' => [
        'title' => 'Üdvözlünk a slot4u-ban',
        'subtitle' => 'Foglalási rendszer — fejlesztés alatt.',
        'badge' => 'M1 · Alapinfrastruktúra',
    ],
    'tenant' => [
        'home' => [
            'title' => 'Foglalási felület',
            'subtitle' => 'Itt jelenik majd meg a publikus foglalófelület.',
            'badge' => 'Tenant',
        ],
        'suspended' => [
            'title' => 'A szolgáltatás átmenetileg nem elérhető',
            'subtitle' => 'Ez a fiók jelenleg fel van függesztve. Kérjük, vedd fel a kapcsolatot az üzemeltetővel.',
            'badge' => 'Felfüggesztve',
        ],
        'dashboard' => [
            'title' => 'Vezérlőpult',
            'subtitle' => 'Itt jelenik majd meg a tenant adminisztrációs felülete.',
            'badge' => 'Tenant',
            'logout' => 'Kijelentkezés',
        ],
    ],
    'auth' => [
        'login' => [
            'title' => 'Bejelentkezés',
            'subtitle' => 'Lépj be a fiókodba.',
            'email' => 'Email cím',
            'password' => 'Jelszó',
            'remember' => 'Emlékezz rám',
            'submit' => 'Belépés',
            'forgot' => 'Elfelejtetted a jelszavad?',
        ],
        'forgot' => [
            'title' => 'Jelszó visszaállítása',
            'subtitle' => 'Add meg az email címed, és küldünk egy visszaállító linket.',
            'email' => 'Email cím',
            'submit' => 'Visszaállító link küldése',
            'back' => 'Vissza a bejelentkezéshez',
        ],
        'reset' => [
            'title' => 'Új jelszó megadása',
            'subtitle' => 'Add meg az új jelszavad.',
            'email' => 'Email cím',
            'password' => 'Új jelszó',
            'password_confirmation' => 'Jelszó megerősítése',
            'submit' => 'Jelszó frissítése',
        ],
        'verify' => [
            'title' => 'Email cím megerősítése',
            'subtitle' => 'Köszönjük a regisztrációt! Mielőtt elkezdenéd, erősítsd meg az email címed a kiküldött linkre kattintva. Ha nem kaptad meg, küldünk újat.',
            'sent' => 'Új megerősítő linket küldtünk az email címedre.',
            'resend' => 'Megerősítő email újraküldése',
            'logout' => 'Kijelentkezés',
        ],
    ],
    'super' => [
        'dashboard' => [
            'title' => 'Superadmin vezérlőpult',
            'subtitle' => 'Központi adminisztrációs felület — fejlesztés alatt.',
            'badge' => 'Superadmin',
        ],
    ],
];
