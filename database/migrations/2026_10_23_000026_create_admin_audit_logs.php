<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organiser_verifications', function (Blueprint $table) {
            $table->text('decision_message')->nullable()->after('review_notes');
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('auditable');
            $table->string('action', 100);
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_id', 'created_at'], 'admin_audit_actor_created');
            $table->index(['subject_user_id', 'created_at'], 'admin_audit_subject_created');
            $table->index(['action', 'created_at'], 'admin_audit_action_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('organiser_verifications', function (Blueprint $table) {
            $table->dropColumn('decision_message');
        });
    }
};
