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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->enum('type', ['credit', 'debit'])->index();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->enum('channel', ['cash', 'upi', 'neft', 'imps', 'internal', 'razorpay'])->default('internal');
            $table->string('reference_no', 32)->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed'])->default('completed')->index();
            $table->uuid('initiated_by')->nullable()->index();
            $table->uuid('related_transaction_id')->nullable()->index();
            $table->uuid('transfer_group_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

