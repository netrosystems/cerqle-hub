<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment versioning
    |--------------------------------------------------------------------------
    |
    | A successful production asset build runs `app:release` and increments the
    | patch version. Runtime state lives in storage so deployments do not dirty
    | the Git worktree or conflict with a later pull.
    |
    */
    'base_version' => env('APP_VERSION', '1.0.0'),
    // Kept outside storage because production storage is commonly owned by the
    // web-server user while deployments/builds run as a separate SSH user.
    // The release directory is ignored by Git.
    'state_path' => base_path('release/current.json'),
    'history_path' => base_path('release/history.json'),
    'history_limit' => 50,
];
