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
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('context', 50)->nullable()->after('payment_method');
            $table->unsignedBigInteger('context_id')->nullable()->after('context');

            $table->index(['context', 'context_id'], 'payment_transactions_context_index');
            $table->index(['order_id', 'context'], 'payment_transactions_order_context_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('payment_transactions_context_index');
            $table->dropIndex('payment_transactions_order_context_index');
            $table->dropColumn(['context', 'context_id']);
        });
    }
};

