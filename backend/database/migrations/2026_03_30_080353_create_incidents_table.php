<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number')->unique();
            $table->string('title');
            $table->text('description');
            $table->enum('type', ['equipment', 'software'])->default('equipment');
            $table->string('category');
            $table->enum('severity', ['P0', 'P1', 'P2', 'P3'])->default('P3');
            $table->enum('status', ['new', 'triaged', 'assigned', 'in_progress', 'resolved', 'closed'])->default('new');
            $table->foreignId('asset_id')->nullable()->constrained();
            $table->string('reporter_name');
            $table->string('reporter_contact')->nullable();
            $table->string('triage_rule_matched')->nullable();
            $table->datetime('sla_respond_by')->nullable();
            $table->datetime('sla_resolve_by')->nullable();
            $table->datetime('responded_at')->nullable();
            $table->datetime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->decimal('resolution_cost', 10, 2)->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
