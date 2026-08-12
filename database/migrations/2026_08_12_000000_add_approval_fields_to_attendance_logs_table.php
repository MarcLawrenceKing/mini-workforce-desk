<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Who signed the log off, when, and — for a rejection — why.
            // All nullable: a pending log has none of them.
            $table->foreignId('approved_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('reject_reason')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // SQLite (used by the test suite) cannot drop a foreign key.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['approved_by']);
            }

            $table->dropColumn(['approved_by', 'approved_at', 'reject_reason']);
        });
    }
};
