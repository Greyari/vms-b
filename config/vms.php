<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Visitor / blacklist import row cap
    |--------------------------------------------------------------------------
    |
    | Hard ceiling on the number of data rows a single CSV/XLSX import may
    | contain. The importer aborts with a 422 before touching the database
    | when a file exceeds this, so a huge or malformed upload can't lock the
    | visitors table inside one long transaction.
    |
    */

    'import_max_rows' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Visitor number floor
    |--------------------------------------------------------------------------
    |
    | Lowest visitor_no the application assigns. Numbers below it are reserved
    | for visitors imported from VMS-old, whose VisitorID is kept as visitor_no
    | (currently at most 347,414). Keeping new numbers above the floor means a
    | delta import on cutover night can never collide with a visitor the new
    | system created in the meantime.
    |
    */

    'visitor_no_floor' => 1_000_000,

    /*
    |--------------------------------------------------------------------------
    | SPA index
    |--------------------------------------------------------------------------
    |
    | The React build's index.html. On the local Windows server the release
    | script copies the build into public/, and every non-API path is answered
    | with this file. Azure serves the SPA from Static Web Apps, so the file is
    | absent there and those paths answer 404.
    |
    */

    'spa_index' => env('VMS_SPA_INDEX', public_path('index.html')),

];
