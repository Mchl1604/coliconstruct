<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The note under the Contact page's Send button.
 *
 * It used to read "Online inquiries are not being accepted yet", which was
 * true while the form was disabled and is a lie now that every message is
 * stored and shown in Configuration > Inquiries.
 *
 * Only rows still carrying that exact sentence are touched. Anything an
 * administrator has already written is theirs, and is left alone.
 */
return new class extends Migration
{
    private const OLD = 'Note: Online inquiries are not being accepted yet.';

    private const NEW = 'We usually reply within one business day.';

    public function up(): void
    {
        DB::table('tbl_system_contents')
            ->where('content_key', 'contact.form_note')
            ->where('content_value', self::OLD)
            ->update([
                'content_value' => self::NEW,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('tbl_system_contents')
            ->where('content_key', 'contact.form_note')
            ->where('content_value', self::NEW)
            ->update([
                'content_value' => self::OLD,
                'updated_at' => now(),
            ]);
    }
};
