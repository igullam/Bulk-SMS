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
        Schema::create('ussd_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('wallet_id')->constrained();
            
            // Payment details
            $table->enum('provider', ['mpesa', 'yas', 'airtel', 'halotel']);
            $table->string('phone_number')->encrypted();
            $table->decimal('amount', 12, 2);
            $table->string('bundle_type')->nullable(); // daily, monthly, yearly
            
            // Transaction tracking
            $table->string('transaction_ref')->unique();
            $table->enum('status', ['pending', 'ussd_sent', 'verified', 'completed', 'failed'])->default('pending');
            $table->string('ussd_code')->nullable(); // *123*1*1# format
            $table->string('verification_token')->unique();
            
            // USSD flow
            $table->longText('ussd_response')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('ussd_sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id', 'status']);
            $table->index(['transaction_ref']);
            $table->index(['phone_number', 'status']);
        });

        Schema::create('ussd_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ussd_payment_id')->constrained();
            $table->string('session_id')->unique();
            $table->string('phone_number')->encrypted();
            $table->enum('provider', ['mpesa', 'yas', 'airtel', 'halotel']);
            $table->longText('user_input')->nullable();
            $table->longText('menu_response')->nullable();
            $table->enum('session_status', ['active', 'completed', 'timeout'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ussd_sessions');
        Schema::dropIfExists('ussd_payments');
    }
};
