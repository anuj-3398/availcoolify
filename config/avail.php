<?php

return [
    // Comma-separated email domains whose Clerk users join Avail Team as members on their own.
    // Everyone else needs an invitation.
    'auto_join_domains' => env('AVAIL_AUTO_JOIN_DOMAINS', 'availproject.org'),

    'guest' => [
        // Access duration preselected in the invite form (days, counted from acceptance).
        'default_days' => 30,
        // Guests get an "access ends soon" email this many days before their access ends.
        'warning_days' => 7,
    ],
];
