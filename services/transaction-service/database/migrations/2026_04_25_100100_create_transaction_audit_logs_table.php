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
        Schema::create('transaction_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_role', 50)->nullable()->index();
            $table->uuid('account_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_audit_logs');
    }
};

