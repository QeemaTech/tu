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
        Schema::create('vendor_subscription_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('current_subscription_id')->nullable();
            $table->boolean('immediate')->default(true);
            $table->enum('status', ['pending_payment', 'paid', 'failed', 'applied', 'cancelled'])->default('pending_payment');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('EGP');
            $table->string('reference')->unique();
            $table->date('scheduled_start_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['plan_id', 'status']);

            $table->foreign('vendor_id', 'vspr_vendor_fk')
                ->references('id')
                ->on('vendors')
                ->cascadeOnDelete();

            $table->foreign('plan_id', 'vspr_plan_fk')
                ->references('id')
                ->on('plans')
                ->cascadeOnDelete();

            $table->foreign('current_subscription_id', 'vspr_curr_sub_fk')
                ->references('id')
                ->on('vendor_subscriptions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_subscription_payment_requests');
    }
};
