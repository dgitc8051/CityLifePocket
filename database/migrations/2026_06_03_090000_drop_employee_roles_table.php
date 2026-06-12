<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // employee_roles 在 v1.0 設計裡是「員工會做哪些工作」，後來改用 stations 系統取代。
        // 此表從未被任何 Controller 寫入，安全 drop。
        Schema::dropIfExists('employee_roles');
    }

    public function down(): void
    {
        // 不還原 — v1.0 的設計已被 stations 取代
    }
};
