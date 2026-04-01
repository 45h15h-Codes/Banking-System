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
        Schema::create('kyc_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->uuid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            
            $table->string('action'); // e.g. "STATUS_CHANGE"
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            
            // Which internal user or admin triggered this
            $table->uuid('performed_by')->nullable();
            
            $table->text('remarks')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_audit_logs');
    }
};
