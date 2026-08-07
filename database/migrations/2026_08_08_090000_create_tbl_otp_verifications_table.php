<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes, for every workflow that needs to prove somebody owns an
 * address: registration, a forgotten password, a change of email, and an
 * administrator-issued reset.
 *
 * The code column holds a hash rather than the six digits themselves. A
 * database dump, a stray log line or a backup therefore hands nobody a working
 * code, which is the same reason passwords are not stored in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_otp_verifications', function (Blueprint $table): void {
            $table->id('otp_id');

            // Null for a code sent to an address that has no account yet, and
            // for a change of email - where the new address is not yet anyone's.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('email');
            $table->string('otp_code');
            $table->string('purpose', 32);

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // The only lookup this table ever performs: the newest live code
            // for one address and one purpose.
            $table->index(['email', 'purpose', 'verified_at'], 'otp_lookup_index');

            // What the housekeeping sweep reads.
            $table->index('expires_at', 'otp_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_otp_verifications');
    }
};
