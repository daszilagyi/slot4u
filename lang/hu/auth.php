<?php

return [
    // Laravel authentication messages (login failures, throttling).
    'failed' => 'A megadott adatok nem egyeznek a nyilvántartásunkkal.',
    'password' => 'A megadott jelszó hibás.',
    // SLO-251: the account was created through Google or Facebook and has no
    // password to compare against.
    'social_only' => 'Ehhez a fiókhoz Google- vagy Facebook-belépés tartozik. Lépj be azzal, vagy állíts be jelszót az „Elfelejtetted a jelszavad?” linken.',
    'throttle' => 'Túl sok belépési kísérlet. Kérjük, próbáld újra :seconds másodperc múlva.',
];
