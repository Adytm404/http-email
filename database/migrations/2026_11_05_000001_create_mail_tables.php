<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20); // smtp | resend | sendgrid | mailgun
            $table->text('config');      // encrypted JSON
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(10);
            $table->unsignedInteger('weight')->default(100);
            $table->unsignedInteger('limit_per_minute')->nullable();
            $table->unsignedInteger('limit_per_hour')->nullable();
            $table->unsignedInteger('limit_per_day')->nullable();
            $table->string('health', 10)->default('healthy'); // healthy | degraded | down
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('priority')->default(10);
            $table->boolean('is_default')->default(false);
            $table->string('strategy', 10)->default('failover'); // failover | weighted
            $table->jsonb('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('provider_routing_rule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(10);
            $table->unsignedInteger('weight')->default(100);
            $table->unique(['routing_rule_id', 'provider_id']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('prefix', 16);
            $table->string('token_hash', 64)->unique();
            $table->string('permission', 20)->default('full_access'); // full_access | sending_access
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from');
            $table->jsonb('to');
            $table->jsonb('cc')->nullable();
            $table->jsonb('bcc')->nullable();
            $table->jsonb('reply_to')->nullable();
            $table->string('subject', 998);
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->jsonb('headers')->nullable();
            $table->jsonb('tags')->nullable();
            $table->string('status', 12)->default('queued'); // scheduled | queued | sending | sent | failed | canceled
            $table->foreignId('routing_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('forced_route')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
            $table->unique(['api_key_id', 'idempotency_key']);
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('email_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('content_type')->nullable();
            $table->string('content_id')->nullable();
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('email_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12); // sent | failed | skipped
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('response')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider_id', 'created_at']);
        });

        Schema::create('provider_usage', function (Blueprint $table) {
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('window', 8); // minute | hour | day
            $table->timestamp('bucket_start');
            $table->unsignedInteger('count')->default(0);
            $table->primary(['provider_id', 'window', 'bucket_start']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->jsonb('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'provider_usage', 'email_attempts', 'email_attachments', 'emails', 'api_keys', 'provider_routing_rule', 'routing_rules', 'providers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
