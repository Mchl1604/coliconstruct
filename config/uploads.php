<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where uploaded files are written
    |--------------------------------------------------------------------------
    |
    | Project documents and completion photographs are moved onto disk with
    | UploadedFile::move(), which is a direct filesystem call rather than a
    | Storage facade one. That is deliberate - these files are served straight
    | out of public/ by asset() - but it has one sharp edge: Storage::fake()
    | does NOT intercept move(), so a test posting a document used to write a
    | real file into the real public/uploads and leave it there forever.
    |
    | Thousands of 0-byte PDFs and fake JPGs accumulated that way, and were
    | committed. Making the root configurable is what lets the test suite send
    | them somewhere disposable instead - see phpunit.xml.
    |
    | UPLOAD_ROOT is read relative to the project root when set. Unset, which
    | is every real environment, this is exactly where it always was.
    |
    */

    'root' => env('UPLOAD_ROOT')
        ? base_path(env('UPLOAD_ROOT'))
        : public_path('uploads'),

    /*
    | The public path the same files are READ back from.
    |
    | Kept separate from the root on purpose: document_path is stored in the
    | database and handed to asset(), so it has to stay relative to public/
    | whatever the write root is. In every real environment the two agree; in
    | the test suite they deliberately do not, and nothing reads the files.
    */

    'public_prefix' => 'uploads',

];
