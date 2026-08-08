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

];
