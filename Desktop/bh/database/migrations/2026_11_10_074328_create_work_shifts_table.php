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
        if (! Schema::hasTable('work_shifts')) {
            Schema::create('work_shifts', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120)->unique();
                $table->time('starts_at');
                $table->time('ends_at');
                $table->string('timezone')->default('Africa/Dar_es_Salaam');
                $table->unsignedSmallInteger('grace_minutes')->default(0);
                $table->boolean('crosses_midnight')->default(false);
                $table->unsignedSmallInteger('break_minutes')->default(0);
                $table->unsignedSmallInteger('grace_period_minutes')->default(0);
                $table->json('days_of_week')->default('["monday","tuesday","wednesday","thursday","friday","saturday","sunday"]');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });

            return;
        }

        Schema::table('work_shifts', function (Blueprint $table): void {
            if (! Schema::hasColumn('work_shifts', 'break_minutes')) {
                $table->unsignedSmallInteger('break_minutes')->default(0);
            }
            if (! Schema::hasColumn('work_shifts', 'grace_period_minutes')) {
                $table->unsignedSmallInteger('grace_period_minutes')->default(0);
            }
            if (! Schema::hasColumn('work_shifts', 'days_of_week')) {
                $table->json('days_of_week')->default('["monday","tuesday","wednesday","thursday","friday","saturday","sunday"]');
            }
            if (! Schema::hasColumn('work_shifts', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
        });

        if (Schema::hasColumn('work_shifts', 'grace_minutes')) {
            DB::table('work_shifts')->update(['grace_period_minutes' => DB::raw('grace_minutes')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('work_shifts')) {
            return;
        }

        Schema::table('work_shifts', function (Blueprint $table): void {
            foreach (['is_active', 'days_of_week', 'grace_period_minutes', 'break_minutes'] as $column) {
                if (Schema::hasColumn('work_shifts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
