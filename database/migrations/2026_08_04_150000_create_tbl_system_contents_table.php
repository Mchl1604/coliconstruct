<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every piece of text and every image on the public website, one row each.
 *
 * Key/value rather than a column per field: the point is that the Super Admin
 * can add a new editable field without a migration, so the schema has to stay
 * indifferent to what the pages happen to show this month.
 *
 * The editor is nullOnDelete - content outlives the account that last touched
 * it, and losing the attribution is better than losing the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_system_contents', function (Blueprint $table) {
            $table->id('content_id');

            // The name a view asks for, e.g. "home.hero_heading".
            $table->string('content_key', 100)->unique();

            // Long enough for an HTML block; images store their disk path.
            $table->text('content_value')->nullable();

            $table->string('content_type', 20)->default('text');
            $table->string('section', 30)->default('home');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The editor lists one section at a time.
            $table->index(['section', 'content_key'], 'system_contents_section_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_system_contents');
    }
};
