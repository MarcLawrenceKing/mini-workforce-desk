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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users');

            $table->foreignId('company_id')->constrained('companies');
            $table->string('employee_no');

            $table->string('first_name');
            $table->string('middle_name');
            $table->string('last_name');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
