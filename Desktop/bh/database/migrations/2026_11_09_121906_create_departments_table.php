<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('department')->constrained()->nullOnDelete();
            $table->index(['department_id', 'employee_no']);
        });

        $legacyDepartments = DB::table('users')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->distinct()
            ->pluck('department');

        foreach ($legacyDepartments as $departmentName) {
            $now = now();
            DB::table('departments')->insertOrIgnore([
                'name' => $departmentName,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $departmentId = DB::table('departments')->where('name', $departmentName)->value('id');
            DB::table('users')->where('department', $departmentName)->update(['department_id' => $departmentId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'employee_no']);
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
