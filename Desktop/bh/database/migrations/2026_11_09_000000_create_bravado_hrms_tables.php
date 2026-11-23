<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_no')->nullable()->unique();
            $table->string('role')->default('employee')->index();
            $table->string('department')->nullable()->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_code')->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manufacturer')->default('ZKTeco');
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->string('firmware_version')->nullable();
            $table->string('ip_address')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('connection_type')->nullable();
            $table->string('adapter_key')->nullable();
            $table->string('integration_status')->default('awaiting_configuration')->index();
            $table->timestampTz('compatibility_verified_at')->nullable();
            $table->foreignId('compatibility_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();
            $table->string('api_token_hash')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('device_employee_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('device_user_id');
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'device_user_id', 'valid_from']);
            $table->index(['employee_id', 'valid_from', 'valid_until']);
        });

        Schema::create('raw_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->string('device_user_id')->nullable();
            $table->string('event_identifier')->nullable();
            $table->timestampTz('event_timestamp');
            $table->timestampTz('received_timestamp');
            $table->string('event_type')->nullable();
            $table->jsonb('raw_payload');
            $table->string('source')->default('zkteco');
            $table->string('processing_status')->default('pending')->index();
            $table->text('validation_message')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'event_identifier']);
            $table->index(['event_timestamp', 'device_id']);
            $table->index(['employee_id', 'event_timestamp']);
        });

        Schema::create('attendance_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('status')->index();
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'started_at']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->date('attendance_date')->index();
            $table->string('source')->index();
            $table->timestampTz('first_in_at')->nullable();
            $table->timestampTz('last_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->string('status')->default('incomplete')->index();
            $table->timestamps();
            $table->unique(['employee_id', 'attendance_date', 'source']);
        });

        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->jsonb('original_values');
            $table->jsonb('revised_values');
            $table->text('reason');
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('attendance_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('configuration');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->boolean('crosses_midnight')->default(false);
            $table->timestamps();
        });
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('work_shift_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'effective_until']);
        });
        Schema::create('registered_computers', function (Blueprint $table) {
            $table->id();
            $table->string('computer_code')->unique();
            $table->string('hostname')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_version')->nullable();
            $table->string('credential_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('computer_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained('registered_computers')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('windows_account');
            $table->string('windows_sid')->nullable();
            $table->timestamps();
            $table->unique(['computer_id', 'windows_account']);
            $table->unique(['computer_id', 'windows_sid']);
        });
        Schema::create('computer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained('registered_computers')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('windows_account');
            $table->string('windows_sid')->nullable();
            $table->unsignedSmallInteger('event_id');
            $table->timestampTz('event_timestamp');
            $table->string('event_identifier')->unique();
            $table->string('processing_status')->default('pending')->index();
            $table->jsonb('raw_payload');
            $table->timestamps();
            $table->index(['employee_id', 'event_timestamp']);
        });
        Schema::create('computer_agent_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->unique()->constrained('registered_computers')->cascadeOnDelete();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_successful_communication_at')->nullable();
            $table->string('status')->default('unknown');
            $table->text('details')->nullable();
            $table->timestamps();
        });
        Schema::create('integration_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['resolved_at', 'created_at']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
        // Create branches after the schema exists, then attach the provisional devices.
        DB::table('branches')->insert([
            ['name' => 'Dar es Salaam', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dodoma', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $branches = DB::table('branches')->pluck('id', 'name');
        foreach ([['ZKT-001', 'Dar es Salaam'], ['ZKT-002', 'Dodoma']] as [$code, $branch]) {
            DB::table('attendance_devices')->insert(['device_code' => $code, 'branch_id' => $branches[$branch], 'manufacturer' => 'ZKTeco', 'integration_status' => 'awaiting_configuration', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'integration_error_logs', 'computer_agent_status', 'computer_events', 'computer_users', 'registered_computers', 'shift_assignments', 'work_shifts', 'attendance_rules', 'attendance_adjustments', 'attendance_records', 'attendance_sync_logs', 'raw_attendance_events', 'device_employee_mappings', 'attendance_devices'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', ['employee_no'])) {
                $table->dropUnique(['employee_no']);
            }
            if (Schema::hasIndex('users', ['role'])) {
                $table->dropIndex(['role']);
            }
            if (Schema::hasIndex('users', ['department'])) {
                $table->dropIndex(['department']);
            }
            if (Schema::hasForeignKey('users', ['branch_id'])) {
                $table->dropForeign(['branch_id']);
            }
            foreach (['employee_no', 'role', 'department', 'branch_id', 'is_active'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('branches');
    }
};
