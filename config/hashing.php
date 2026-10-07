<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Vision Law hashes passwords with Argon2id (PLT-02). The Laravel scaffold
    | default (bcrypt) is not used.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Argon2id Options
    |--------------------------------------------------------------------------
    |
    | PLT-02 hashing parameters: 64 MiB memory, 3 iterations, 1 thread.
    | (Laravel's HashManager reads these from the hashing.argon key for
    | both the argon2i and argon2id drivers.)
    |
    | Each parameter is overridable via environment so the test suite can run
    | with weaker (faster) parameters — see the 001-D08 comment in phpunit.xml.
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON2ID_MEMORY', 65536),
        'time' => (int) env('ARGON2ID_TIME', 3),
        'threads' => (int) env('ARGON2ID_THREADS', 1),
    ],

];
