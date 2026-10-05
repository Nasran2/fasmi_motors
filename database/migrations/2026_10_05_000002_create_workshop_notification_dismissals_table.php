<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_notification_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_key', 64);
            $table->timestamps();
            $table->unique(['user_id', 'notification_key'], 'workshop_notification_dismissals_user_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_notification_dismissals');
    }
};
