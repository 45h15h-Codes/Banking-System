<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->string('account_number', 16)->unique();
            $table->enum('type', ['savings', 'current']);
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active')->index();
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
