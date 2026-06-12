<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('line_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('type', 64)->comment('schedule.published | leave.approved | ...');
            $table->json('payload_json');
            $table->timestamp('sent_at')->nullable();
            $table->string('line_message_id', 128)->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void { Schema::dropIfExists('line_notifications'); }
};
