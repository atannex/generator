<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default pattern
    |--------------------------------------------------------------------------
    |
    | Used by models whose CodeOptions do not set a format. See the README for
    | every token: {prefix} {year} {yy} {abbr:3} {random:6} {alnum:8} {seq:5}…
    |
    */

    'pattern' => '{prefix}{yy}{abbr:3}{random:6}',

    /*
    | Attempts at finding an unused code before giving up.
    */

    'max_attempts' => 15,

    /*
    |--------------------------------------------------------------------------
    | Sequences ({seq} token)
    |--------------------------------------------------------------------------
    |
    | Counters live in this table. A null connection uses the default one.
    |
    */

    'sequences' => [
        'table' => 'generator_sequences',
        'connection' => null,
    ],

];
