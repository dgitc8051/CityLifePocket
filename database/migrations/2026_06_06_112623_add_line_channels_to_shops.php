<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // 既有：line_channel_id（Messaging API channel id）、line_channel_secret_encrypted
            // 補上 Login channel 跟 LIFF 跟 token

            if (! Schema::hasColumn('shops', 'line_login_channel_id')) {
                $table->string('line_login_channel_id', 32)->nullable()->after('line_channel_id');
            }
            if (! Schema::hasColumn('shops', 'line_login_channel_secret_encrypted')) {
                $table->text('line_login_channel_secret_encrypted')->nullable()->after('line_login_channel_id');
            }
            if (! Schema::hasColumn('shops', 'line_messaging_access_token_encrypted')) {
                $table->text('line_messaging_access_token_encrypted')->nullable()->after('line_channel_secret_encrypted');
            }
            if (! Schema::hasColumn('shops', 'line_liff_id')) {
                $table->string('line_liff_id', 32)->nullable()->after('line_login_channel_secret_encrypted');
            }
            if (! Schema::hasColumn('shops', 'line_bot_user_id')) {
                $table->string('line_bot_user_id', 64)->nullable()->after('line_messaging_access_token_encrypted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            foreach ([
                'line_login_channel_id', 'line_login_channel_secret_encrypted',
                'line_messaging_access_token_encrypted', 'line_liff_id', 'line_bot_user_id',
            ] as $col) {
                if (Schema::hasColumn('shops', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
