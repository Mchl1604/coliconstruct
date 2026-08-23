<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Everything a person uploads: profile pictures, project documents,
        | completion photographs, task and report images.
        |
        | Private, and on purpose. Nothing here is served by the web server -
        | every read goes through a route that checks who is asking, so a file
        | is reachable by the people the project is reachable by and nobody
        | else. A URL that leaked out of a browser's history used to be a
        | permanent, unauthenticated key to a client's contract.
        |
        | The driver is the one thing that changes between environments. Left
        | alone it is a local directory outside public/, which is what the test
        | suite and a development machine want. Set UPLOADS_DRIVER=s3 with the
        | keys below and the same code writes to Cloudflare R2, Amazon S3 or
        | anything else speaking that protocol - which is what a deployment
        | wants, because a container's own disk is emptied by every deploy.
        */
        'uploads' => [
            'driver' => env('UPLOADS_DRIVER', 'local'),
            // UPLOADS_ROOT is read relative to the project root when set,
            // which is how the test suite keeps its files out of the real
            // one. Unset - every other environment - this is where they go.
            'root' => env('UPLOADS_ROOT')
                ? base_path((string) env('UPLOADS_ROOT'))
                : storage_path('app/uploads'),
            'key' => env('UPLOADS_KEY'),
            'secret' => env('UPLOADS_SECRET'),
            // R2 has no regions; 'auto' is what it expects.
            'region' => env('UPLOADS_REGION', 'auto'),
            'bucket' => env('UPLOADS_BUCKET'),
            'endpoint' => env('UPLOADS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('UPLOADS_PATH_STYLE', true),
            'visibility' => 'private',
            // A missing file is a 404 from the route that asked for it, not a
            // 500 from the filesystem.
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
