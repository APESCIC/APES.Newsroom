<?php

/*
|--------------------------------------------------------------------------
| Ghost newsletter mapping
|--------------------------------------------------------------------------
|
| Each Ghost newsletter maps to exactly one Newsroom newsletter (no merging).
| Used by `php artisan ghost:reconcile-newsletters`, which expects one
| filtered Ghost members export per entry, named "<key>.csv", next to the
| full "Export all members" file (members.csv). See
| docs/migration/ghost-newsletters.md.
|
| ghost_id / ghost_slug come from the Ghost Content API for active
| newsletters; archived Ghost newsletters are not exposed there and are
| matched by name. `expected` is the Ghost subscriber count used for
| reconciliation sign-off.
|
*/

return [
    'members_file' => 'members.csv',

    'newsletters' => [
        [
            'key' => 'newsroom',
            'ghost_id' => '672c97a8e94cc6004bbdbd58',
            'ghost_slug' => 'default-newsletter',
            'ghost_name' => 'Association of Protecting Exotic Species CIC Newsroom',
            'newsroom_slug' => 'apes-cic',
            'status' => 'active',
            'expected' => 101,
        ],
        [
            'key' => 'apes-times',
            'ghost_id' => '6731d970bd626800014bd254',
            'ghost_slug' => 'apes-times',
            'ghost_name' => 'APES Times',
            'newsroom_slug' => 'apes-times',
            'status' => 'active',
            'expected' => 9,
        ],
        [
            'key' => 'shelter-rescue',
            'ghost_id' => '67309d23bd626800014bd244',
            'ghost_slug' => 'shelter-rescue',
            'ghost_name' => 'APES Shelter & Rescue',
            'newsroom_slug' => 'apes-shelter-rescue',
            'status' => 'active',
            'expected' => 15,
        ],
        [
            'key' => 'pet-care-clinic',
            'ghost_id' => '67309d78bd626800014bd246',
            'ghost_slug' => 'apes-pet-care-clinic',
            'ghost_name' => 'APES Pet Care Clinic',
            'newsroom_slug' => 'apes-pet-care-clinic',
            'status' => 'active',
            'expected' => 15,
        ],
        [
            'key' => 'myapes-portal',
            'ghost_id' => '673205debd626800014bd314',
            'ghost_slug' => 'myapes-portal-newsroom',
            'ghost_name' => 'MyAPES Portal',
            'newsroom_slug' => 'myapes-portal',
            'status' => 'active',
            'expected' => 8,
        ],
        [
            'key' => 'stream-4-apes',
            'ghost_id' => '6755a77a27d73b00012c1843',
            'ghost_slug' => 'stream-4-apes',
            'ghost_name' => 'Stream 4 APES',
            'newsroom_slug' => 'stream-4-apes',
            'status' => 'active',
            'expected' => 6,
        ],
        [
            'key' => 'pet-shop-newsroom',
            'ghost_id' => null,
            'ghost_slug' => null,
            'ghost_name' => 'Pet Shop Newsroom',
            'newsroom_slug' => 'pet-shop-newsroom',
            'status' => 'archived',
            'expected' => 1,
        ],
        [
            'key' => 'mailing-list',
            'ghost_id' => null,
            'ghost_slug' => null,
            'ghost_name' => 'Mailing List',
            'newsroom_slug' => 'mailing-list',
            'status' => 'archived',
            'expected' => 107,
        ],
        [
            'key' => 'animal-therapy-waiting-list',
            'ghost_id' => null,
            'ghost_slug' => null,
            'ghost_name' => 'Animal Therapy Waiting List',
            'newsroom_slug' => 'animal-therapy-waiting-list',
            'status' => 'archived',
            'expected' => 2,
        ],
    ],
];
