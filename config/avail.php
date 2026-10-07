<?php

return [
    // Comma-separated email domains whose Clerk users join Avail Team as members on their own.
    // Everyone else needs an invitation.
    'auto_join_domains' => env('AVAIL_AUTO_JOIN_DOMAINS', 'availproject.org'),

    // Read availcoolify.json (or the headers and redirects of vercel.json) from the repo during a deploy.
    'routing_rules_enabled' => env('AVAIL_ROUTING_RULES', true),

    'guest' => [
        // Access duration preselected in the invite form (days, counted from acceptance).
        'default_days' => 30,
        // Guests get an "access ends soon" email this many days before their access ends.
        'warning_days' => 7,
    ],
];
