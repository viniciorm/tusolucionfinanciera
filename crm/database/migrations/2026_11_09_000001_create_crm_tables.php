<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 191)->unique();
            $t->string('name')->nullable();
            $t->string('google_subject', 128)->nullable()->unique();
            $t->string('role', 20)->default('advisor')->index();
            $t->boolean('active')->default(false)->index();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('leads', function (Blueprint $t): void {
            $t->id();
            $t->string('source_key', 64)->unique();
            $t->string('source_sheet', 32);
            $t->string('meta_id', 191);
            $t->string('name')->nullable();
            $t->string('email')->nullable()->index();
            $t->string('phone', 100)->nullable();
            $t->string('phone_search', 100)->nullable()->index();
            $t->string('received_at_raw')->nullable();
            $t->timestamp('received_at')->nullable()->index();
            $t->string('campaign')->nullable()->index();
            $t->string('form')->nullable()->index();
            $t->string('notification_status')->nullable();
            $t->string('source_assigned_to')->nullable();
            $t->boolean('reconciliation_pending')->default(true)->index();
            $t->json('original');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();

            $t->unique(['source_sheet', 'meta_id']);
        });

        Schema::create('lead_management', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 40)->default('Nuevo')->index();
            $t->timestamps();
        });

        Schema::create('activities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('actor_user_id')->constrained('users');
            $t->string('type', 30)->index();
            $t->text('note');
            $t->timestamps();

            $t->index(['lead_id', 'created_at']);
        });

        Schema::create('follow_ups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('assigned_user_id')->constrained('users');
            $t->timestamp('due_at')->index();
            $t->timestamp('completed_at')->nullable()->index();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->string('action', 80)->index();
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('sync_runs', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 30)->index();
            $t->unsignedInteger('rows_read')->default(0);
            $t->unsignedInteger('rows_saved')->default(0);
            $t->unsignedInteger('rows_rejected')->default(0);
            $t->json('summary')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        // Tabla de sesiones estándar de Laravel para hosting compartido
        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        // Tablas de caché y bloqueos atómicos para comandos artisan
        Schema::create('cache', function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('lead_management');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('users');
    }
};
