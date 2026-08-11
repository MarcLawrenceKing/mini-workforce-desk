<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // These legacy columns were superseded by log_in_time/log_out_time.
            // Keeping them nullable makes this safe for databases already migrated.
            $table->time('log_in')->nullable()->change();
            $table->time('log_out')->nullable()->change();
            $table->text('notes')->nullable()->change();
            $table->string('status', 20)->default('pending')->change();
            $table->unique(['employee_id', 'date']);
        });

        DB::table('attendance_logs')->where('status', 'PENDING')->update(['status' => 'pending']);
        DB::table('attendance_logs')->where('status', 'APPROVED')->update(['status' => 'approved']);
        DB::table('attendance_logs')->where('status', 'REJECTED')->update(['status' => 'rejected']);
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
        });
    }
};
