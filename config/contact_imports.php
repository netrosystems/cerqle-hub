<?php

return [
    /* A Contact List is a campaign audience container, not an unlimited data
       warehouse. This limit is enforced for CSV imports and existing-contact
       selection on the server; the browser only mirrors it for clarity. */
    'max_contacts_per_list' => max(1, (int) env('CONTACT_LIST_MAX_CONTACTS', 250000)),

    /* Keep this below the web-server/PHP request ceiling so Laravel can
       return a useful error instead of an Nginx 413 response. */
    'max_file_mb' => max(1, (int) env('CONTACT_LIST_IMPORT_MAX_FILE_MB', 20)),

    /* Only this many rows are eligible from an upload. Additional rows are
       counted and reported as ignored instead of failing the whole import. */
    'max_rows_per_file' => min(
        max(1, (int) env('CONTACT_LIST_IMPORT_MAX_ROWS', 50000)),
        max(1, (int) env('CONTACT_LIST_MAX_CONTACTS', 250000)),
    ),
];
