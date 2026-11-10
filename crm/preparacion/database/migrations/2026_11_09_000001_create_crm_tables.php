<?php
// Preparación: ejecutar solo después de integrar y probar en un proyecto Laravel aislado.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('email', 191)->unique(); $t->string('name')->nullable();
            $t->string('firebase_uid', 128)->nullable()->unique();
            $t->string('role', 20)->default('advisor'); $t->boolean('active')->default(false); $t->timestamps();
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id(); $t->string('source_key', 64)->unique(); $t->string('source_sheet', 32);
            $t->string('meta_id', 191); $t->string('name')->nullable(); $t->string('email')->nullable()->index();
            $t->string('phone', 100)->nullable(); $t->string('phone_search', 100)->nullable()->index();
            $t->string('received_at_raw')->nullable(); $t->timestamp('received_at')->nullable()->index();
            $t->string('campaign')->nullable()->index(); $t->string('form')->nullable()->index();
            $t->string('notification_status')->nullable(); $t->string('source_assigned_to')->nullable();
            $t->boolean('reconciliation_pending')->default(true)->index(); $t->json('original');
            $t->timestamp('last_seen_at')->nullable(); $t->timestamps(); $t->unique(['source_sheet','meta_id']);
        });
        Schema::create('lead_management', function (Blueprint $t) {
            $t->id(); $t->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 40)->default('Nuevo')->index(); $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id(); $t->foreignId('lead_id')->constrained('leads'); $t->foreignId('actor_user_id')->constrained('users');
            $t->string('type', 30); $t->text('note'); $t->timestamps(); $t->index(['lead_id','created_at']);
        });
        Schema::create('follow_ups', function (Blueprint $t) {
            $t->id(); $t->foreignId('lead_id')->constrained('leads'); $t->foreignId('assigned_user_id')->constrained('users');
            $t->timestamp('due_at')->index(); $t->timestamp('completed_at')->nullable(); $t->text('note')->nullable(); $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('actor_user_id')->nullable()->constrained('users'); $t->foreignId('lead_id')->nullable()->constrained('leads');
            $t->string('action', 80)->index(); $t->json('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('sync_runs', function (Blueprint $t) {
            $t->id(); $t->string('status', 30); $t->unsignedInteger('rows_read')->default(0);
            $t->unsignedInteger('rows_saved')->default(0); $t->unsignedInteger('rows_rejected')->default(0);
            $t->json('summary')->nullable(); $t->timestamp('started_at'); $t->timestamp('finished_at')->nullable();
        });
    }
    public function down(): void
    {
        foreach (['sync_runs','audit_events','follow_ups','activities','lead_management','leads','users'] as $table) Schema::dropIfExists($table);
    }
};
