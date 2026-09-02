<?php

namespace App\Models;

use App\Services\InquirySpamGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable piece of the system: a word or picture on the public website,
 * or an operational setting an administrator may change.
 *
 * The catalogue below - DEFINITIONS - is the only place that decides what is
 * editable. Adding a field means adding a line there; the table, the editor
 * and the pages all follow from it, with no migration and no new columns.
 *
 * Sections come in two groups. SECTIONS is the public website, edited in
 * Configuration -> System Settings -> System Contents. SETTINGS_SECTIONS is
 * everything that changes how the system behaves rather than how it reads -
 * the confirmation window, the enquiry cooldown, the Terms and Conditions -
 * edited in the card beneath it. Both are the same table, the same service and
 * the same editor; only the list of pills differs, because "rewrite the About
 * page" and "complete projects after five days instead of seven" are not the
 * same kind of decision and should not sit in one undifferentiated list.
 */
class SystemContent extends Model
{
    protected $table = 'tbl_system_contents';

    protected $primaryKey = 'content_id';

    protected $fillable = [
        'content_key',
        'content_value',
        'content_type',
        'section',
        'updated_by',
    ];

    // ------------------------------------------------------------------
    // Sections and types
    // ------------------------------------------------------------------

    public const SECTION_BRANDING = 'branding';

    public const SECTION_HOME = 'home';

    public const SECTION_ABOUT = 'about';

    public const SECTION_CONTACT = 'contact';

    public const SECTION_FOOTER = 'footer';

    /**
     * How long a project may wait on its client, and what else the project
     * workflow lets an administrator set.
     */
    public const SECTION_PROJECT_SETTINGS = 'project_settings';

    /**
     * How often one visitor may write in from the public Contact form.
     */
    public const SECTION_INQUIRY_SETTINGS = 'inquiry_settings';

    /**
     * The Terms and Conditions, and anything else that is a legal statement
     * rather than marketing copy.
     */
    public const SECTION_LEGAL = 'legal';

    /**
     * The public website's sections, in the order the editor shows them.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        self::SECTION_BRANDING => 'Branding',
        self::SECTION_HOME => 'Home Page',
        self::SECTION_ABOUT => 'About Page',
        self::SECTION_CONTACT => 'Contact Page',
        self::SECTION_FOOTER => 'Footer',
    ];

    /**
     * The operational sections - things that change what the system does.
     *
     * @var array<string, string>
     */
    public const SETTINGS_SECTIONS = [
        self::SECTION_PROJECT_SETTINGS => 'Project Settings',
        self::SECTION_INQUIRY_SETTINGS => 'Inquiry Settings',
        self::SECTION_LEGAL => 'Terms & Conditions',
    ];

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_HTML = 'html';

    public const TYPE_IMAGE = 'image';

    public const TYPE_URL = 'url';

    /**
     * A whole number typed into a spinner rather than a free-text box.
     *
     * Stored as a string like everything else here - the column holds text and
     * one column for every type would be a schema per setting - and read back
     * through SystemContentService::number(), which is what turns it into the
     * integer the application uses.
     */
    public const TYPE_NUMBER = 'number';

    /**
     * An hour of the clock, stored as 'HH:MM' on a 24-hour clock with the
     * minutes always at nought.
     *
     * The editor draws the hours as a list to choose from rather than a time
     * box to type into, so there is no minute to edit. Everything a setting of
     * this kind bounds is counted by the hour - the pickers it feeds, the
     * availability slots, the validation behind them - so a value like 08:30
     * is not a finer setting but one nothing downstream could honour.
     */
    public const TYPE_HOUR = 'hour';

    /**
     * Repeatable public content. These values have a richer editor than a
     * plain text field, but still live in the same system-content table.
     */
    public const TYPE_SERVICE_LIST = 'service_list';

    public const TYPE_OWNER_LIST = 'owner_list';

    /**
     * A supporting value used by a repeatable field. It is stored for the
     * application, not shown as a separate input in System Settings.
     */
    public const TYPE_HIDDEN = 'hidden';

    /**
     * The choices a field of a given type offers, when it offers a fixed list.
     *
     * Kept here rather than in the controller so the catalogue stays the one
     * description of a field: what it is, what it may hold, and what it may be
     * set to.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, array{value: string, label: string}>
     */
    public static function optionsFor(array $definition): array
    {
        return ($definition['type'] ?? null) === self::TYPE_HOUR
            ? Schedule::hourOptions()
            : [];
    }

    /**
     * The types that hold an uploaded file rather than typed-in words.
     *
     * @var array<int, string>
     */
    public const FILE_TYPES = [self::TYPE_IMAGE];

    /**
     * Every editable field: key => [label, type, section, help, default].
     *
     * The defaults are what the site falls back to before anything has been
     * written, so a fresh installation renders a complete page rather than a
     * skeleton full of gaps.
     *
     * @var array<string, array{label: string, type: string, section: string, help?: string, default?: string}>
     */
    public const DEFINITIONS = [
        // -------------------------------------------------------------- Branding
        'branding.company_name' => [
            'label' => 'Company Name',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_BRANDING,
            'default' => 'Coliconstruct Engineering Services',
        ],
        'branding.short_name' => [
            'label' => 'Short Name',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_BRANDING,
            'help' => 'Shown beside the logo in the header, where there is less room.',
            'default' => 'Coliconstruct',
        ],
        'branding.website_title' => [
            'label' => 'Website Title',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_BRANDING,
            'help' => 'The browser tab title for public pages.',
            'default' => 'Coliconstruct Engineering Services',
        ],
        'branding.logo' => [
            'label' => 'Company Logo',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_BRANDING,
        ],
        'branding.footer_logo' => [
            'label' => 'Footer Logo',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_BRANDING,
        ],
        'branding.favicon' => [
            'label' => 'Browser Favicon',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_BRANDING,
        ],

        // ------------------------------------------------------------------ Home
        //
        // The order below is the order the page reads in: the hero, then the
        // services grid, then the closing call to action. The editor lists a
        // section in catalogue order, so keeping the two in step is what makes
        // the form navigable without a preview open beside it.
        'home.hero_badge' => [
            'label' => 'Hero Badge',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'help' => 'The small yellow pill above the headline. Leave empty to hide it.',
            'default' => 'HVAC specialists since 2012',
        ],
        'home.hero_heading' => [
            'label' => 'Hero Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'HVAC systems, engineered and done properly.',
        ],
        'home.hero_description' => [
            'label' => 'Hero Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'Heating, ventilation and air-conditioning for homes and businesses - designed, installed, cleaned and maintained by technicians you can track from booking to handover.',
        ],
        'home.hero_primary_label' => [
            'label' => 'Hero Button (Yellow)',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'help' => 'Opens My Projects.',
            'default' => 'View Projects',
        ],
        'home.hero_secondary_label' => [
            'label' => 'Hero Button (Outlined)',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'help' => 'Opens the About page.',
            'default' => 'Learn More',
        ],
        'home.hero_image' => [
            'label' => 'Hero Image',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_HOME,
            'help' => 'The framed photograph beneath the hero text. A wide shot works best.',
        ],
        'home.services_eyebrow' => [
            'label' => 'Services Eyebrow',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'help' => 'The small blue line above the services heading.',
            'default' => 'What we offer',
        ],
        'home.services_heading' => [
            'label' => 'Services Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'Our Services',
        ],
        'home.services_intro' => [
            'label' => 'Services Intro',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'help' => 'Optional. Sits under the heading; leave empty to hide it.',
            'default' => 'Complete HVAC work, from a single unit to a whole building.',
        ],
        'home.services' => [
            'label' => 'Services',
            'type' => self::TYPE_SERVICE_LIST,
            'section' => self::SECTION_HOME,
            'help' => 'Add, edit, remove, and order the services shown on the website. Each service can have its own image.',
            'default' => "HVAC Installation | Heating, ventilation and air-conditioning systems sized, mounted, ducted and commissioned for homes and businesses.\nHVAC Cleaning | Scheduled cleaning of units, coils and ductwork that keeps a system efficient and the air in your building clean.\nHVAC Maintenance | Planned servicing and repair that keeps heating, ventilation and cooling running through the year.",
        ],
        'home.service_ids' => [
            'label' => 'Service image links',
            'type' => self::TYPE_HIDDEN,
            'section' => self::SECTION_HOME,
            'hidden' => true,
        ],
        'home.promo_heading' => [
            'label' => 'Call to Action Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'help' => 'The yellow strip that closes the page.',
            'default' => 'Ready to get started?',
        ],
        'home.promo_body' => [
            'label' => 'Call to Action Text',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'Reach out and let us talk about your project.',
        ],
        'home.promo_button_label' => [
            'label' => 'Call to Action Button',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'help' => 'Opens the Contact page.',
            'default' => 'Contact Us',
        ],

        // ----------------------------------------------------------------- About
        //
        // In page order: the grey introduction panel, the journey, the blue
        // values band, the owners, then the closing strip.
        'about.eyebrow' => [
            'label' => 'Introduction Eyebrow',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'help' => 'The small blue line above the page title.',
            'default' => 'About us',
        ],
        'about.heading' => [
            'label' => 'About Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'About Coliconstruct',
        ],
        'about.description' => [
            'label' => 'About Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'default' => 'An engineering services company built around HVAC work, and around finishing what we start.',
        ],
        'about.journey_eyebrow' => [
            'label' => 'Journey Eyebrow',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'Our journey',
        ],
        'about.journey_heading' => [
            'label' => 'Journey Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'How we got here',
        ],
        'about.history' => [
            'label' => 'Journey Text',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'default' => 'Coliconstruct Engineering Services began as a small team taking on residential air-conditioning work, and has grown into an HVAC contractor handling commercial installations, ducting fabrication, ventilation and scheduled maintenance.',
        ],
        'about.team_image' => [
            'label' => 'Journey Image',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_ABOUT,
            'help' => 'The photograph beside the journey text.',
        ],
        'about.values_eyebrow' => [
            'label' => 'Values Eyebrow',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'Our values',
        ],
        'about.values_heading' => [
            'label' => 'Values Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'What drives us',
        ],
        'about.core_values' => [
            'label' => 'Values',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'help' => 'One value per line, as "Title | Description".',
            'default' => "Workmanship | The job is finished when it is right, not when it is over.\nHonesty | A quotation that holds, and a schedule we mean to keep.\nSafety | Every site left as safe as we found it, for our people and yours.\nAccountability | One team owns your project from assessment to handover.",
        ],
        'about.team_eyebrow' => [
            'label' => 'Team Eyebrow',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'Our team',
            'hidden' => true,
        ],
        'about.team_heading' => [
            'label' => 'Team Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'The people behind it',
            'hidden' => true,
        ],
        'about.team' => [
            'label' => 'Team Members',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'help' => 'One person per line, as "Name | Role". Their photographs are the four fields below, in the same order. Leave empty to hide the section.',
            'default' => '',
            'hidden' => true,
        ],
        'about.team_photo_1' => ['label' => 'Team Photo 1', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT, 'hidden' => true],
        'about.team_photo_2' => ['label' => 'Team Photo 2', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT, 'hidden' => true],
        'about.team_photo_3' => ['label' => 'Team Photo 3', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT, 'hidden' => true],
        'about.team_photo_4' => ['label' => 'Team Photo 4', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT, 'hidden' => true],
        'about.owners_eyebrow' => [
            'label' => 'Owners Eyebrow',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'Our owners',
        ],
        'about.owners_heading' => [
            'label' => 'Owners Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'The people behind Coliconstruct',
        ],
        'about.owners' => [
            'label' => 'Owners',
            'type' => self::TYPE_OWNER_LIST,
            'section' => self::SECTION_ABOUT,
            'help' => 'Add each owner with their name, contact details, and optional profile image. Owners appear in this order on the About page.',
        ],
        'about.cta_heading' => [
            'label' => 'Call to Action Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'help' => 'The yellow strip that closes the page.',
            'default' => 'Want to work with us?',
        ],
        'about.cta_body' => [
            'label' => 'Call to Action Text',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'default' => 'We would love to hear about your project.',
        ],
        'about.cta_button_label' => [
            'label' => 'Call to Action Button',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'help' => 'Opens the Contact page.',
            'default' => 'Contact Us',
        ],

        // --------------------------------------------------------------- Contact
        'contact.heading' => [
            'label' => 'Contact Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
            'default' => 'Contact Us',
        ],
        'contact.description' => [
            'label' => 'Contact Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => 'Tell us what you need and we will come back with an assessment schedule.',
        ],
        'contact.form_heading' => [
            'label' => 'Message Form Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
            'default' => 'Leave us a message',
        ],
        'contact.form_intro' => [
            'label' => 'Message Form Intro',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => 'Tell us what you need and we will come back to you with an assessment schedule.',
        ],
        'contact.form_button_label' => [
            'label' => 'Message Form Button',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
            'default' => 'Send message',
        ],
        'contact.form_note' => [
            'label' => 'Message Form Note',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            // The form sends for real now - every message is stored and shown
            // in Configuration > Inquiries - so this line is no longer an
            // apology for a disabled form. It is whatever the company wants a
            // visitor to know before they write.
            'help' => 'Shown under the Send button - for example, how soon somebody replies. Leave it empty to show nothing.',
            'default' => 'We usually reply within one business day.',
        ],
        'contact.info_heading' => [
            'label' => 'Contact Information Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
            'default' => 'Contact Information',
        ],
        'contact.info_intro' => [
            'label' => 'Contact Information Intro',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => 'You can also contact or visit us using these details.',
        ],
        'contact.address' => [
            'label' => 'Company Address',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => '72P2+96 General Mariano Alvarez, Cavite',
        ],
        'contact.phone' => [
            'label' => 'Phone Numbers',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
            'help' => 'Separate several numbers with a comma.',
        ],
        'contact.email' => [
            'label' => 'Email Address',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
        ],
        'contact.map_embed' => [
            'label' => 'Google Maps Embed URL',
            'type' => self::TYPE_URL,
            'section' => self::SECTION_CONTACT,
            'help' => 'The src URL from the Google Maps "Embed a map" share option.',
        ],
        // The four the footer shows, in the order it shows them. A link left
        // empty simply drops out of the row.
        'contact.facebook' => ['label' => 'Facebook', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],
        'contact.telegram' => ['label' => 'Telegram', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],
        'contact.whatsapp' => ['label' => 'WhatsApp', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],
        'contact.instagram' => ['label' => 'Instagram', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],

        // ---------------------------------------------------------------- Footer
        'footer.description' => [
            'label' => 'Footer Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_FOOTER,
            'default' => 'HVAC works - heating, ventilation and air-conditioning - for homes and businesses across Metro Manila and Cavite.',
        ],
        'footer.links_heading' => [
            'label' => 'Navigation Column Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_FOOTER,
            'default' => 'Navigation',
        ],
        'footer.quick_links' => [
            'label' => 'Navigation Links',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_FOOTER,
            'help' => 'One link per line, as "Label | /path".',
            'default' => "Home | /\nMy Projects | /my-projects\nAbout | /about\nContact Us | /contact",
        ],
        'footer.contact_heading' => [
            'label' => 'Information Column Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_FOOTER,
            'default' => 'Information',
        ],
        'footer.socials_heading' => [
            'label' => 'Socials Column Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_FOOTER,
            'default' => 'Socials',
        ],
        'footer.copyright' => [
            'label' => 'Copyright Text',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_FOOTER,
            'help' => 'Use :year for the current year.',
            'default' => 'Coliconstruct Engineering Services. Established in 2012.',
        ],

        // ------------------------------------------------------- Project Settings
        //
        // Operational settings rather than website copy, and in this catalogue
        // because it already IS the settings store: one table, one cached read,
        // one editor, one audit entry, one permission. A second mechanism for
        // "numbers an administrator may change" would be the same thing twice.
        //
        // Each of the three below carries its own `rules`, which is the only
        // structural addition: the website's fields are all "some text, not too
        // long", and these are not - a completion window of nought days or an
        // empty set of terms would break the thing they configure.
        'project_settings.auto_completion_days' => [
            'label' => 'Automatic Project Completion (days)',
            'type' => self::TYPE_NUMBER,
            'section' => self::SECTION_PROJECT_SETTINGS,
            'help' => 'Automatically complete a project after it remains awaiting client confirmation for this many days. The client is reminded shortly before the deadline.',
            // Concatenated rather than cast: this is a constant expression,
            // where a cast is not allowed and `. ''` is. Written from the
            // constant either way, so the shipped default and the runtime
            // fallback cannot drift apart.
            'default' => Project::DEFAULT_COMPLETION_CONFIRMATION_DAYS.'',
            'rules' => ['required', 'integer', 'min:1', 'max:365'],
            'messages' => [
                'required' => 'Enter the number of days before a project completes automatically.',
                'integer' => 'The number of days must be a whole number.',
                'min' => 'The number of days must be at least 1.',
                'max' => 'The number of days cannot be more than 365.',
            ],
        ],

        // The window a partial-day booking may be made in.
        //
        // A pair rather than two independent numbers: neither one means
        // anything without the other, and the end has to be later than the
        // start or there is no window at all. That relationship is declared
        // once, here, as `before` on the earlier of the two - the editor reads
        // it to check the pair in the browser and SystemContentController
        // reads the same entry to check it again on the way in, so there is
        // one statement of the rule rather than two that can disagree.
        //
        // Partial days only. A whole-day range runs midnight to midnight and
        // has no hours to bound, so nothing here reaches one.
        'project_settings.partial_day_start_hour' => [
            'label' => 'Partial Day Start Hour',
            'type' => self::TYPE_HOUR,
            'section' => self::SECTION_PROJECT_SETTINGS,
            'help' => 'The earliest a partial-day schedule may start. Whole hours only - it feeds the time pickers, the availability checks and the validation behind them.',
            // Read from the model so the shipped default and the runtime
            // fallback cannot drift apart.
            'default' => Schedule::DEFAULT_PARTIAL_DAY_START,
            'rules' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):00$/'],
            'before' => 'project_settings.partial_day_end_hour',
            'messages' => [
                'required' => 'Enter the hour a partial day may start at.',
                'regex' => 'Choose a start time on the hour, such as 08:00.',
                'before' => 'The partial day end hour must be later than the start hour.',
            ],
        ],
        'project_settings.partial_day_end_hour' => [
            'label' => 'Partial Day End Hour',
            'type' => self::TYPE_HOUR,
            'section' => self::SECTION_PROJECT_SETTINGS,
            'help' => 'The latest a partial-day schedule may end. Whole hours only, and later than the start hour.',
            'default' => Schedule::DEFAULT_PARTIAL_DAY_END,
            'rules' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):00$/'],
            'messages' => [
                'required' => 'Enter the hour a partial day may end at.',
                'regex' => 'Choose an end time on the hour, such as 17:00.',
            ],
        ],

        // ------------------------------------------------------- Inquiry Settings
        'inquiry_settings.submission_limit_minutes' => [
            'label' => 'Inquiry Submission Limit (minutes)',
            'type' => self::TYPE_NUMBER,
            'section' => self::SECTION_INQUIRY_SETTINGS,
            'help' => 'How long a visitor must wait before sending another message from the public Contact form. The form never mentions it - somebody who writes in too soon simply sees a notice asking them to try again later.',
            'default' => InquirySpamGuard::DEFAULT_SUBMISSION_LIMIT_MINUTES.'',
            'rules' => ['required', 'integer', 'min:1', 'max:1440'],
            'messages' => [
                'required' => 'Enter the number of minutes between inquiry submissions.',
                'integer' => 'The limit must be a whole number of minutes.',
                'min' => 'The limit must be at least 1 minute.',
                'max' => 'The limit cannot be more than 1440 minutes (24 hours).',
            ],
        ],

        // ------------------------------------------------------------------ Legal
        // Plain text, and nothing but. Whoever writes the company's terms is a
        // person with something to say about the company's terms, not somebody
        // who should have to close a <p> tag to say it - and markup typed into
        // a box is markup that can be typed wrong, on the one page a visitor
        // has to read before they agree to anything.
        //
        // Nothing is substituted in on the way out either. What is typed here
        // is what the page shows, word for word: a legal document that quietly
        // rewrites itself between the textarea and the screen is one nobody can
        // proof-read. Blank lines separate paragraphs and the page renders the
        // text as written; that is the whole of it.
        'legal.terms_and_conditions' => [
            'label' => 'Terms and Conditions',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_LEGAL,
            'help' => 'Shown wherever the system asks somebody to accept the terms, exactly as written here.',
            'default' => self::DEFAULT_TERMS,
            'rules' => ['required', 'string', 'max:50000'],
            'messages' => [
                'required' => 'The Terms and Conditions cannot be empty.',
                'max' => 'The Terms and Conditions are too long to store.',
            ],
        ],
    ];

    /**
     * The Terms and Conditions as the system ships with them.
     *
     * Word for word what the registration dialog used to have written into its
     * markup, minus the markup: a numbered heading is a line of its own and a
     * blank line ends a paragraph, which is the only formatting the page needs
     * and the only formatting anybody has to type.
     *
     * Written out in full, including the company's name and the confirmation
     * window. This is a starting draft rather than a live document - the
     * company is expected to replace it with its own terms - and a draft that
     * reads as finished English is more useful than one peppered with tokens
     * whose meaning has to be looked up.
     */
    private const DEFAULT_TERMS = <<<'TEXT'
        Please read these terms before opening a Coliconstruct Registered User account.

        1. Your account
        A Registered User account is for following the work Coliconstruct is carrying out for you. You are responsible for the accuracy of the details you register with, for keeping your password to yourself, and for everything done through your account. Tell us at once if you believe somebody else has access to it.

        2. Who may register
        You must be at least 18 years old. Registering on behalf of a company means you are authorised to do so. Accounts for Coliconstruct staff are created by an administrator and are never opened from this form.

        3. Verifying your email address
        Your account is not active until you enter the code we send to the address you register with. That address is how we identify you, how your projects reach your account, and how we contact you about them.

        4. Project information
        Schedules, progress reports, photographs and completion records shown in your account describe work as it stands and may change as the work proceeds. Where a project is reported as complete you will be asked to confirm it; if you do not respond within 7 days, the system records the project as completed on your behalf. Quotations, contracts and any other document in your account remain governed by the signed agreement between us.

        5. Your information
        We collect your name, email address, contact number and date of birth to operate your account, and we use them for that purpose. We do not sell your information. Records connected to your projects are retained as part of our business records.

        6. Acceptable use
        Do not attempt to reach another Registered User's projects, interfere with the system, or use it for anything unlawful. We may deactivate an account that is used this way.

        7. Availability and changes
        The system may be unavailable during maintenance or for reasons outside our control. We may update these terms; continuing to use your account after a change means you accept the updated terms.

        8. Contact
        Questions about these terms can be sent to the company using the contact details on our website.
        TEXT;

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }

    public function scopeInSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section);
    }

    /**
     * @return array<string, array{label: string, type: string, section: string, help?: string, default?: string}>
     */
    public static function definitionsFor(string $section): array
    {
        return array_filter(
            self::DEFINITIONS,
            fn (array $definition): bool => $definition['section'] === $section
        );
    }

    /**
     * Every section the editor knows, whichever card draws it.
     *
     * The two lists are kept apart for the pills and joined here for
     * everything else - the controller's "is this a real section?" guard, the
     * label it logs, the label it hands back. Splitting that check as well
     * would mean two ways to answer one question.
     *
     * @return array<string, string>
     */
    public static function allSections(): array
    {
        return self::SECTIONS + self::SETTINGS_SECTIONS;
    }

    /**
     * A section's human label, or null when there is no such section.
     */
    public static function sectionLabel(string $section): ?string
    {
        return self::allSections()[$section] ?? null;
    }

    /**
     * Whether this section holds operational settings rather than website
     * copy - which is what decides how strictly the editor validates it.
     */
    public static function isSettingsSection(string $section): bool
    {
        return isset(self::SETTINGS_SECTIONS[$section]);
    }

    public static function isImageKey(string $key): bool
    {
        return in_array(self::definitionForKey($key)['type'] ?? null, self::FILE_TYPES, true);
    }

    /**
     * Dynamic image keys belong to one repeatable service or owner. Keeping
     * the id in the key means images remain paired with their row even when
     * the editor changes the row order.
     *
     * @return array<string, mixed>|null
     */
    public static function definitionForKey(string $key): ?array
    {
        if (isset(self::DEFINITIONS[$key])) {
            return self::DEFINITIONS[$key];
        }

        if (preg_match('/^home\\.service_image\\.[0-9a-f-]{36}$/i', $key) === 1) {
            return [
                'label' => 'Service Image',
                'type' => self::TYPE_IMAGE,
                'section' => self::SECTION_HOME,
            ];
        }

        if (preg_match('/^about\\.owner_image\\.[0-9a-f-]{36}$/i', $key) === 1) {
            return [
                'label' => 'Owner Image',
                'type' => self::TYPE_IMAGE,
                'section' => self::SECTION_ABOUT,
            ];
        }

        return null;
    }

    public static function isSpecialEditorType(string $type): bool
    {
        return in_array($type, [self::TYPE_SERVICE_LIST, self::TYPE_OWNER_LIST, self::TYPE_HIDDEN], true);
    }
}
