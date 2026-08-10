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
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');

            $table->enum('request_type', ['LEAVE', 'OVERTIME']);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');

            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->text('reason')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->text('reject_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
