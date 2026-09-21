<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Releases still in beta
    |--------------------------------------------------------------------------
    |
    | While true, the Admin → Releases create form defaults to the Beta channel
    | and seeded/baseline Change Log Hub notes use Beta. Flip to false when
    | Newsroom leaves beta; historical release rows stay on Beta.
    |
    */

    'releases_in_beta' => (bool) env('NEWSROOM_RELEASES_IN_BETA', true),

];
