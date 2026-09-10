<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clears out the button labels Configuration used to offer.
 *
 * Configuration owns the site's words and imagery, not its controls: a
 * button's wording is part of what the page does rather than what it says, and
 * it is written in the view it belongs to now. Their definitions are gone from
 * SystemContent::DEFINITIONS, which means the editor no longer lists them and
 * SystemContentService::saveText() no longer writes them - but any row already
 * stored against one of these keys would sit in the table for good, unreadable
 * and unclearable, so it goes here.
 *
 * Nothing on the public site changes either way: the buttons stopped reading
 * these rows in the same change that removed the definitions.
 */
return new class extends Migration
{
    /**
     * The keys as they were defined, not as they are - there is nothing left
     * in the model to read them from.
     *
     * @var array<int, string>
     */
    private const KEYS = [
        'home.hero_primary_label',
        'home.hero_secondary_label',
        'home.promo_button_label',
        'about.cta_button_label',
        'contact.form_button_label',
    ];

    public function up(): void
    {
        DB::table('tbl_system_contents')->whereIn('content_key', self::KEYS)->delete();
    }

    /**
     * Nothing to put back. The rows held the same words the views now carry,
     * and without a definition behind them they would be read by nothing.
     */
    public function down(): void
    {
        //
    }
};
