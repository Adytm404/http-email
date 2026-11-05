<?php

use App\Auth\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->jsonb('permissions')->nullable();
            // The owner role always has every permission and cannot be edited or deleted.
            $table->boolean('is_owner')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        $now = now();
        $all = Permission::keys();
        $roles = [
            ['Owner', 'Full access, including users and roles.', $all, true],
            ['Admin', 'Everything except users and roles.', array_values(array_diff($all, ['manage_users', 'manage_roles'])), false],
            ['Developer', 'API keys, send log and email content.', ['view_dashboard', 'view_emails', 'view_email_content', 'send_emails', 'manage_api_keys'], false],
            ['Viewer', 'Read-only overview and send log, without email content.', ['view_dashboard', 'view_emails'], false],
        ];
        foreach ($roles as [$name, $description, $permissions, $owner]) {
            DB::table('roles')->insert([
                'name' => $name,
                'description' => $description,
                'permissions' => json_encode($permissions),
                'is_owner' => $owner,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Existing accounts were full admins before roles existed.
        DB::table('users')->update(['role_id' => DB::table('roles')->where('is_owner', true)->value('id')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['is_active', 'last_login_at']);
        });
        Schema::dropIfExists('roles');
    }
};
