<?php

return [
    'measurement' => [
        'off' => 'Off',
        'none' => 'None',
        'unverified' => 'Unverified: no root template found',
        'not_placed_one' => 'Not placed in 1 root template',
        'not_placed_many' => 'Not placed in :count root templates',
        'placed_one' => 'Placed in 1 root template',
        'placed_many' => 'Placed in :count root templates',
    ],
    'events' => [
        'host_empty' => 'Declared by the host · no parameters',
        'host_parameters' => 'Declared by the host · :parameters',
        'package_empty' => 'Declared by a package · no parameters',
        'package_parameters' => 'Declared by a package · :parameters',
    ],
    'period' => ['last_days' => 'Last :days days'],
    'traffic' => [
        'needs_worker' => 'needs a queue worker to measure a tenant',
        'not_connected' => 'Not connected',
        'not_measured' => 'Not measured yet',
        'not_measured_reason' => 'Not measured yet (:reason)',
        'failed' => "Couldn't be measured (:when)",
        'refresh_failed' => 'last refresh failed',
        'cannot_refresh' => "Can't refresh (:reason)",
        'refreshing' => 'refreshing',
        'unknown' => 'unknown',
        'none_measured' => 'None measured',
        'measured' => ':when · :period',
        'measured_note' => ':when · :period · :note',
    ],
];
