<?php

return [
    /*
    | Secret that unlocks the /setup page, which runs database migrations and
    | creates the first (and only) user. Leave empty to turn /setup off.
    */
    'setup_token' => env('SETUP_TOKEN', ''),
];
