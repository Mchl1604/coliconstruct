<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company Identity
    |--------------------------------------------------------------------------
    |
    | The branding every system email carries: the name in the header, the
    | logo above it, and the tagline beneath. Read from the environment so a
    | deployment can rebrand without touching code.
    |
    */

    'name' => env('COMPANY_NAME', env('APP_NAME', 'ColiConstruct')),

    'tagline' => env('COMPANY_TAGLINE', 'Project Management System'),

    /*
    | The logo shown at the top of every email.
    |
    | Mail clients cannot read a relative path, so this is resolved to an
    | absolute URL against APP_URL when the template renders. Point it at a
    | publicly reachable file - an image behind authentication will not load
    | in somebody's inbox.
    */
    'logo' => env('COMPANY_LOGO', 'img/coliconstructlogor.png'),

    /*
    |--------------------------------------------------------------------------
    | Footer Contact Details
    |--------------------------------------------------------------------------
    |
    | What appears in the footer of every email. Anything left empty is simply
    | omitted rather than printed blank, so a deployment can fill in as much or
    | as little as it has.
    |
    */

    'address' => env('COMPANY_ADDRESS', ''),

    'phone' => env('COMPANY_PHONE', ''),

    'email' => env('COMPANY_EMAIL', env('MAIL_FROM_ADDRESS', '')),

    'website' => env('COMPANY_WEBSITE', env('APP_URL', '')),

    /*
    |--------------------------------------------------------------------------
    | Palette
    |--------------------------------------------------------------------------
    |
    | Email clients ignore stylesheets, so these are inlined by the template.
    | They match the interface's primary colour, which keeps a message and the
    | page it links to looking like the same system.
    |
    */

    'colors' => [
        'primary' => env('COMPANY_COLOR_PRIMARY', '#0d6efd'),
        'header' => env('COMPANY_COLOR_HEADER', '#0b2545'),
    ],

];
