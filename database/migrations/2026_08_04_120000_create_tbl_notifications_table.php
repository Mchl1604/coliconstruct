<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person per event: the same project being completed produces a
 * notification for the lead, one for each technician, and one for the client,
 * because each of them reads and dismisses it separately.
 *
 * Deleting the recipient's account takes their notifications with it - unlike
 * the audit trail, there is nobody left for these to be addressed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_notifications', function (Blueprint $table) {
            $table->id('notification_id');

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->string('message', 500);
            $table->string('module', 50);

            // What the notification is about, so a duplicate for the same
            // event and recipient can be recognised without parsing text.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Where clicking it goes. Stored rather than derived so an entry
            // still resolves after the routing around it changes.
            $table->string('url', 500)->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The bell reads "my unread, newest first"; the centre reads
            // "mine, newest first, narrowed by module".
            $table->index(['user_id', 'created_at'], 'notifications_user_created_index');
            $table->index(['user_id', 'is_read', 'created_at'], 'notifications_user_unread_index');
            $table->index(['user_id', 'module', 'created_at'], 'notifications_user_module_index');
            $table->index(['reference_type', 'reference_id'], 'notifications_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_notifications');
    }
};
