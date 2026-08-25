<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spam protection for the public Contact form
    |--------------------------------------------------------------------------
    |
    | The Contact form is the one thing a stranger can make this application
    | do, so it is the one endpoint with no account behind it at all. These are
    | the limits that keep a script - or somebody leaning on the button - from
    | filling Configuration > Inquiries with noise.
    |
    | One of them is on a screen and the rest are not, and the line between
    | them is whose decision it is. How long one sender waits between messages
    | is the company's - short during a campaign, long while being pestered -
    | so it is a Super Admin setting, and `per_ip.decay_seconds` below is only
    | what that setting falls back to on a deployment that set it here before
    | the setting existed. See InquirySpamGuard::submissionLimitMinutes().
    |
    | The per-address caps stay here. Those are a property of the deployment,
    | changed once when a site turns out to sit behind a busy shared address
    | and never again, and putting them on screen would only invite somebody to
    | switch the protection off.
    |
    | Every limit is enforced server side by InquirySpamGuard. Nothing in the
    | browser is trusted to hold any of them.
    |
    */

    /*
    | One address, one enquiry, per window.
    |
    | The blunt instrument, and the reason the email limits below exist beside
    | it: an office, a school or a phone network puts many legitimate people
    | behind a single address, so this is kept short enough that a second
    | person is delayed rather than turned away.
    |
    | `decay_seconds` is the fallback for the Inquiry Submission Limit setting,
    | not the window itself - the setting is what the guard actually counts by.
    */
    'per_ip' => [
        'max' => (int) env('INQUIRY_MAX_PER_IP', 1),
        'decay_seconds' => (int) env('INQUIRY_IP_WINDOW_SECONDS', 600),
    ],

    /*
    | What one email address may send, whatever address it connects from.
    |
    | The half that actually identifies a sender. Two windows rather than one:
    | the hourly cap stops a burst, and the daily cap stops a patient script
    | that waits politely between them.
    */
    'per_email' => [
        'hourly' => [
            'max' => (int) env('INQUIRY_MAX_PER_EMAIL_HOUR', 3),
            'decay_seconds' => 3600,
        ],

        'daily' => [
            'max' => (int) env('INQUIRY_MAX_PER_EMAIL_DAY', 10),
            'decay_seconds' => 86400,
        ],
    ],

    /*
    | The field no person ever sees. Named for something a browser will not
    | offer to autofill, and stated here so the form and the guard cannot
    | drift apart.
    */
    'honeypot_field' => 'company_website',

];
