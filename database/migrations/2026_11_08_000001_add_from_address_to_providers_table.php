<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            // Default sender for this provider ("Acme <noreply@acme.com>"). When set, mail sent through
            // the provider uses it instead of the From of the email, e.g. so a provider only ever sends
            // from a domain that is verified with it.
            $table->string('from_address', 320)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('from_address');
        });
    }
};
