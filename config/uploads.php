<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where uploaded files live
    |--------------------------------------------------------------------------
    |
    | One disk for everything a person uploads - see the `uploads` entry in
    | config/filesystems.php, which is where the local-or-object-storage choice
    | is actually made. Named here so that a call site says what kind of file
    | it is handling rather than repeating a disk name.
    |
    */

    'disk' => env('UPLOADS_DISK', 'uploads'),

    /*
    | The folders within it, one per kind of upload. Kept in one place because
    | the delete paths have to agree with the write paths, and a typo in either
    | is a file that is never cleaned up or never found.
    */

    'folders' => [
        'profile_photos' => 'profile-photos',
        'documents' => 'documents',
        'completion_photos' => 'completion-photos',
        'task_images' => 'task-images',
        'report_images' => 'report-images',
        'system_contents' => 'system-contents',
    ],

];
