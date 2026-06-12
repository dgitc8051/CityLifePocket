<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('phone');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            // 打卡時定位（GPS verification）
            $table->decimal('clock_in_lat', 10, 7)->nullable()->after('clocked_in_at');
            $table->decimal('clock_in_lng', 10, 7)->nullable()->after('clock_in_lat');
            $table->decimal('clock_out_lat', 10, 7)->nullable()->after('clocked_out_at');
            $table->decimal('clock_out_lng', 10, 7)->nullable()->after('clock_out_lat');
            $table->boolean('location_verified')->default(false)->after('clock_out_lng');

            // 遲到 / 加班分鐘
            $table->unsignedInteger('late_minutes')->default(0)->after('location_verified');
            // 系統算出的加班分鐘（未經店家核可，不計薪）
            $table->unsignedInteger('overtime_minutes_detected')->default(0)->after('late_minutes');
            // 店家核可後的加班分鐘（實際計薪用）
            $table->unsignedInteger('overtime_minutes_approved')->default(0)->after('overtime_minutes_detected');

            $table->foreignId('overtime_approved_by')->nullable()->after('overtime_minutes_approved')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('overtime_approved_at')->nullable()->after('overtime_approved_by');
        });

        // 店家打卡定位設定（geofence）
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('clock_in_lat', 10, 7)->nullable()->after('timezone');
            $table->decimal('clock_in_lng', 10, 7)->nullable()->after('clock_in_lat');
            // 允許半徑（公尺）。null 代表不檢查位置
            $table->unsignedInteger('clock_in_radius_m')->nullable()->after('clock_in_lng');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['clock_in_lat', 'clock_in_lng', 'clock_in_radius_m']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overtime_approved_by');
            $table->dropColumn([
                'clock_in_lat', 'clock_in_lng',
                'clock_out_lat', 'clock_out_lng',
                'location_verified',
                'late_minutes',
                'overtime_minutes_detected',
                'overtime_minutes_approved',
                'overtime_approved_at',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('birthday');
        });
    }
};
