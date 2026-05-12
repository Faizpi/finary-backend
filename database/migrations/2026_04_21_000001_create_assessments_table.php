<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // ── 5 ML fields (required by /classify) ──────────────────
            $table->decimal('monthly_income', 14, 2)->default(0);
            $table->decimal('monthly_expense', 14, 2)->default(0);
            $table->decimal('actual_savings', 14, 2)->default(0);
            $table->decimal('budget_goal', 14, 2)->default(0);
            $table->decimal('emergency_fund', 14, 2)->default(0);
            // ── ML result ─────────────────────────────────────────────
            $table->string('classification', 40)->nullable(); // survival | stable | growth
            $table->float('ml_score')->nullable();
            $table->string('ml_explanation', 500)->nullable();
            $table->json('metadata')->nullable();
            // ── Legacy (kept nullable for seeder/compat) ──────────────
            $table->string('financial_status')->nullable();
            $table->string('economic_condition')->nullable();
            $table->json('income_sources')->nullable();
            $table->string('financial_goal')->nullable();
            $table->unsignedSmallInteger('available_hours_per_week')->nullable()->default(0);
            $table->json('skills')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
