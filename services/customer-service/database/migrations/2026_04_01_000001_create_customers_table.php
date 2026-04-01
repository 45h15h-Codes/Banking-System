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
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Link to the user ID from Auth service
            $table->uuid('user_id')->unique();
            
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('dob');
            
            // Encrypted columns need more space than plain strings
            // Laravel defaults to max 255 but let's make it text to be safe with encryption
            $table->text('pan_number')->nullable();
            $table->text('aadhaar_number')->nullable();
            
            $table->text('address')->nullable();
            
            $table->enum('kyc_status', ['pending', 'under_review', 'approved', 'rejected'])
                  ->default('pending');
                  
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
