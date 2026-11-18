<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('shop_type')->default('workshop')->index();
            $table->date('income_date')->index();
            $table->string('title', 150);
            $table->string('source_name', 150)->nullable();
            $table->foreignId('account_id')->constrained();
            $table->string('destination_account', 40)->index();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30);
            $table->string('reference_number', 100)->nullable();
            $table->text('note')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        if (Schema::hasTable('roles')) {
            $grants = [
                'Admin' => ['extra_income.view', 'extra_income.add', 'extra_income.edit', 'extra_income.delete'],
                'Accountant' => ['extra_income.view', 'extra_income.add', 'extra_income.edit', 'extra_income.delete'],
                'Manager' => ['extra_income.view'],
            ];

            foreach ($grants as $roleName => $addedPermissions) {
                $role = DB::table('roles')->where('name', $roleName)->first();
                if (! $role) {
                    continue;
                }
                $current = json_decode($role->permissions ?? '[]', true);
                if (! is_array($current)) {
                    $current = [];
                }
                $merged = array_values(array_unique(array_merge($current, $addedPermissions)));
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($merged)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_incomes');
    }
};
