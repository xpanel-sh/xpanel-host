<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xflow_workflows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('scope', 20)->default('account');
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('trigger_type', 30)->default('manual');
            $table->json('trigger_config')->nullable();
            $table->json('nodes');
            $table->json('edges');
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'trigger_type', 'next_run_at']);
        });

        Schema::create('xflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_id')->constrained('xflow_workflows')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 30);
            $table->string('status', 20)->default('queued');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workflow_id', 'created_at']);
        });

        Schema::create('xflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('xflow_runs')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_type', 30);
            $table->string('handler', 80);
            $table->string('label', 120);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xflow_run_steps');
        Schema::dropIfExists('xflow_runs');
        Schema::dropIfExists('xflow_workflows');
    }
};
