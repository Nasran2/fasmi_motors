<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. SMS Settings table
        if (! Schema::hasTable('sms_settings')) {
            Schema::create('sms_settings', function (Blueprint $table) {
                $table->id();
                $table->string('provider_name')->default('Textit.biz');
                $table->boolean('enabled')->default(false);
                $table->string('api_method')->default('GET'); // GET or POST
                $table->string('gateway_url')->default('https://textit.biz/sendmsg');
                $table->string('textit_id')->nullable();
                $table->text('textit_password')->nullable(); // encrypted
                $table->string('default_country_code')->default('94');
                $table->string('sender_id')->nullable();
                $table->string('id_param')->default('id');
                $table->string('password_param')->default('pw');
                $table->string('to_param')->default('to');
                $table->string('text_param')->default('text');
                $table->string('ref_param')->default('ref');
                $table->string('schedule_param')->default('schd');
                $table->string('success_keyword')->default('OK');
                $table->boolean('auto_sms_enabled')->default(false);
                $table->boolean('manual_sms_enabled')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        // 2. Expand sms_logs with missing columns
        Schema::table('sms_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_logs', 'phone_number')) {
                $table->string('phone_number')->nullable()->after('id');
            }
            if (! Schema::hasColumn('sms_logs', 'formatted_phone')) {
                $table->string('formatted_phone')->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('sms_logs', 'recipient_name')) {
                $table->string('recipient_name')->nullable();
            }
            if (! Schema::hasColumn('sms_logs', 'recipient_type')) {
                $table->string('recipient_type')->nullable(); // customer, technician, custom
            }
            if (! Schema::hasColumn('sms_logs', 'module')) {
                $table->string('module')->nullable(); // bike_intake, job_card, invoice, manual, etc.
            }
            if (! Schema::hasColumn('sms_logs', 'message_type')) {
                $table->string('message_type')->nullable();
            }
            if (! Schema::hasColumn('sms_logs', 'reference_no')) {
                $table->string('reference_no')->nullable();
            }
            if (! Schema::hasColumn('sms_logs', 'provider')) {
                $table->string('provider')->default('textit')->nullable();
            }
            if (! Schema::hasColumn('sms_logs', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable();
            }
            if (! Schema::hasColumn('sms_logs', 'failure_reason')) {
                $table->text('failure_reason')->nullable();
            }
            if (! Schema::hasColumn('sms_logs', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0);
            }
            if (! Schema::hasColumn('sms_logs', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
        });

        // 3. Expand sms_templates with missing columns
        Schema::table('sms_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_templates', 'category')) {
                $table->string('category')->nullable()->after('event');
            }
            if (! Schema::hasColumn('sms_templates', 'auto_send')) {
                $table->boolean('auto_send')->default(false);
            }
            if (! Schema::hasColumn('sms_templates', 'status')) {
                $table->string('status')->default('active'); // active, inactive
            }
            if (! Schema::hasColumn('sms_templates', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (! Schema::hasColumn('sms_templates', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_settings');
    }
};
