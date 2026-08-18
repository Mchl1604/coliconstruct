<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable piece of the public website.
 *
 * The catalogue below - DEFINITIONS - is the only place that decides what is
 * editable. Adding a field means adding a line there; the table, the editor
 * and the pages all follow from it, with no migration and no new columns.
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
     * The tabs of the editor, in the order it shows them.
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

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_HTML = 'html';

    public const TYPE_IMAGE = 'image';

    public const TYPE_URL = 'url';

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
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'help' => 'One service per line, as "Title | Description". They are numbered in this order. Blank lines are ignored.',
            'default' => "HVAC Installation | Heating, ventilation and air-conditioning systems sized, mounted, ducted and commissioned for homes and businesses.\nHVAC Cleaning | Scheduled cleaning of units, coils and ductwork that keeps a system efficient and the air in your building clean.\nHVAC Maintenance | Planned servicing and repair that keeps heating, ventilation and cooling running through the year.",
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
        // values band, the team, then the closing strip.
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
        ],
        'about.team_heading' => [
            'label' => 'Team Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_ABOUT,
            'default' => 'The people behind it',
        ],
        'about.team' => [
            'label' => 'Team Members',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'help' => 'One person per line, as "Name | Role". Their photographs are the four fields below, in the same order. Leave empty to hide the section.',
            'default' => '',
        ],
        'about.team_photo_1' => ['label' => 'Team Photo 1', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],
        'about.team_photo_2' => ['label' => 'Team Photo 2', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],
        'about.team_photo_3' => ['label' => 'Team Photo 3', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],
        'about.team_photo_4' => ['label' => 'Team Photo 4', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],
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
            'default' => '© Coliconstruct Engineering Services. Established in 2012.',
        ],
    ];

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

    public static function isImageKey(string $key): bool
    {
        return in_array(self::DEFINITIONS[$key]['type'] ?? null, self::FILE_TYPES, true);
    }
}
