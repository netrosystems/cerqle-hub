<?php

return [
    /* Keep this below the web-server/PHP request ceiling so Laravel can
       return a useful error instead of an Nginx 413 response. */
    'max_file_mb' => max(1, (int) env('CONTACT_LIST_IMPORT_MAX_FILE_MB', 20)),

    /* Validation keeps a per-file phone deduplication index in memory. Larger
       audiences can be split across files and imported into the same list. */
    'max_rows_per_file' => max(1, (int) env('CONTACT_LIST_IMPORT_MAX_ROWS', 250000)),
];
