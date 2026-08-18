<?php

use App\Models\Inquiry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message written in from the public Contact page.
 *
 * Until now an enquiry left nothing behind but an audit entry and an email, so
 * nobody could say which ones had been answered. This is that record: what was
 * written, what state it is in, and - once somebody replies - what was said
 * back and by whom.
 *
 * Deliberately unattached. An enquiry names no client and no project, because
 * at the moment it arrives there is no account behind it and no work booked;
 * the name and address are stored as the enquirer typed them.
 *
 * Archiving follows the accounts table exactly - a flag, a timestamp and the
 * administrator responsible - because nothing here is ever deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_inquiries', function (Blueprint $table): void {
            $table->id('inquiry_id');

            // As the enquirer typed them. The lengths match what the public
            // form accepts, so a message that passed validation always fits.
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('subject', 150);
            $table->text('message');

            /** @see Inquiry::STATUSES */
            $table->string('status', 20)->default(Inquiry::STATUS_NEW);

            // The reply, kept beside the message it answers rather than in a
            // table of its own: an enquiry is answered once, and the details
            // page has to show both halves together.
            $table->text('reply_message')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();

            // created_at is the date submitted; updated_at is the date the
            // record last changed. Both are shown, so neither is derived.
            $table->timestamps();

            // The table is read one way - active, narrowed by status, newest
            // first - and the archive is read the same way from the other side.
            $table->index(['is_archived', 'status', 'created_at'], 'inquiries_archived_status_created_index');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_inquiries');
    }
};
