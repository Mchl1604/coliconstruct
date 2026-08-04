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
        'home.hero_image' => [
            'label' => 'Hero Banner Image',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_HOME,
        ],
        'home.hero_heading' => [
            'label' => 'Hero Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'Air-conditioning and ducting, done properly.',
        ],
        'home.hero_description' => [
            'label' => 'Hero Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'Installation, repair, cleaning and ducting works for homes and businesses, delivered by technicians you can track from booking to handover.',
        ],
        'home.services_heading' => [
            'label' => 'Featured Services Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'What We Do',
        ],
        'home.services_intro' => [
            'label' => 'Featured Services Intro',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'From a single split-type unit to a full ducting retrofit.',
        ],
        'home.services' => [
            'label' => 'Featured Services',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'help' => 'One service per line, as "Title | Description". Blank lines are ignored.',
            'default' => "Aircon Installation | New units sized, mounted and commissioned for homes and offices.\nAircon Repair | Diagnosis and repair of split-type, window and centralised systems.\nAircon Cleaning | Scheduled cleaning that keeps units efficient and air clean.\nDucting Fabrication | Ducting built to the measurements your building actually needs.\nDucting Installation | Full installation and retrofit for improved ventilation coverage.",
        ],
        'home.overview_heading' => [
            'label' => 'Company Overview Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'Who We Are',
        ],
        'home.overview_body' => [
            'label' => 'Company Overview',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'Coliconstruct Engineering Services carries out air-conditioning and ducting work across Metro Manila and Cavite, for residential and commercial clients alike.',
        ],
        'home.overview_image' => [
            'label' => 'Company Overview Image',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_HOME,
        ],
        'home.mission' => [
            'label' => 'Mission',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'To deliver dependable air-conditioning and ducting work, on the day we promised and to the standard we quoted.',
        ],
        'home.vision' => [
            'label' => 'Vision',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'To be the engineering partner clients call first, and recommend without hesitating.',
        ],
        'home.gallery_heading' => [
            'label' => 'Gallery Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'Recent Work',
        ],
        'home.gallery_1' => ['label' => 'Gallery Image 1', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_HOME],
        'home.gallery_2' => ['label' => 'Gallery Image 2', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_HOME],
        'home.gallery_3' => ['label' => 'Gallery Image 3', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_HOME],
        'home.gallery_4' => ['label' => 'Gallery Image 4', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_HOME],
        'home.gallery_5' => ['label' => 'Gallery Image 5', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_HOME],
        'home.gallery_6' => ['label' => 'Gallery Image 6', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_HOME],
        'home.promo_image' => [
            'label' => 'Promotional Banner',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_HOME,
        ],
        'home.promo_heading' => [
            'label' => 'Promotional Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_HOME,
            'default' => 'Booked work you can actually follow',
        ],
        'home.promo_body' => [
            'label' => 'Promotional Text',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_HOME,
            'default' => 'Every client gets an account. Sign in to see your schedule, the technicians assigned to you, and progress as it happens.',
        ],

        // ----------------------------------------------------------------- About
        'about.banner' => [
            'label' => 'About Banner',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_ABOUT,
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
            'default' => 'An engineering services company built around air-conditioning and ducting work, and around finishing what we start.',
        ],
        'about.history' => [
            'label' => 'Company History',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'default' => 'Coliconstruct Engineering Services began as a small team taking on residential air-conditioning work, and has grown into a company handling commercial installations, ducting fabrication and scheduled maintenance.',
        ],
        'about.mission' => [
            'label' => 'Mission',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'default' => 'To deliver dependable air-conditioning and ducting work, on the day we promised and to the standard we quoted.',
        ],
        'about.vision' => [
            'label' => 'Vision',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'default' => 'To be the engineering partner clients call first, and recommend without hesitating.',
        ],
        'about.core_values' => [
            'label' => 'Core Values',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_ABOUT,
            'help' => 'One value per line, as "Title | Description".',
            'default' => "Workmanship | The job is finished when it is right, not when it is over.\nHonesty | A quotation that holds, and a schedule we mean to keep.\nSafety | Every site left as safe as we found it, for our people and yours.\nAccountability | One team owns your project from assessment to handover.",
        ],
        'about.team_image' => [
            'label' => 'Team Image',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_ABOUT,
        ],
        'about.photo_1' => ['label' => 'Company Photo 1', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],
        'about.photo_2' => ['label' => 'Company Photo 2', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],
        'about.photo_3' => ['label' => 'Company Photo 3', 'type' => self::TYPE_IMAGE, 'section' => self::SECTION_ABOUT],

        // --------------------------------------------------------------- Contact
        'contact.banner' => [
            'label' => 'Contact Banner',
            'type' => self::TYPE_IMAGE,
            'section' => self::SECTION_CONTACT,
        ],
        'contact.heading' => [
            'label' => 'Contact Heading',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_CONTACT,
            'default' => 'Get in Touch',
        ],
        'contact.description' => [
            'label' => 'Contact Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => 'Tell us what you need and we will come back with an assessment schedule.',
        ],
        'contact.address' => [
            'label' => 'Company Address',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => 'Carmona, Cavite, Philippines',
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
        'contact.office_hours' => [
            'label' => 'Office Hours',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_CONTACT,
            'default' => "Monday to Friday, 8:00 AM - 5:00 PM\nSaturday, 8:00 AM - 12:00 NN",
        ],
        'contact.map_embed' => [
            'label' => 'Google Maps Embed URL',
            'type' => self::TYPE_URL,
            'section' => self::SECTION_CONTACT,
            'help' => 'The src URL from the Google Maps "Embed a map" share option.',
        ],
        'contact.facebook' => ['label' => 'Facebook', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],
        'contact.instagram' => ['label' => 'Instagram', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],
        'contact.linkedin' => ['label' => 'LinkedIn', 'type' => self::TYPE_URL, 'section' => self::SECTION_CONTACT],
        'contact.other_social' => [
            'label' => 'Other Social Link',
            'type' => self::TYPE_URL,
            'section' => self::SECTION_CONTACT,
        ],

        // ---------------------------------------------------------------- Footer
        'footer.description' => [
            'label' => 'Footer Description',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_FOOTER,
            'default' => 'Air-conditioning and ducting works for homes and businesses across Metro Manila and Cavite.',
        ],
        'footer.quick_links' => [
            'label' => 'Quick Links',
            'type' => self::TYPE_TEXTAREA,
            'section' => self::SECTION_FOOTER,
            'help' => 'One link per line, as "Label | /path".',
            'default' => "Home | /\nMy Projects | /my-projects\nAbout | /about\nContact Us | /contact",
        ],
        'footer.copyright' => [
            'label' => 'Copyright Text',
            'type' => self::TYPE_TEXT,
            'section' => self::SECTION_FOOTER,
            'help' => 'Use :year for the current year.',
            'default' => '© :year Coliconstruct Engineering Services. All rights reserved.',
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
