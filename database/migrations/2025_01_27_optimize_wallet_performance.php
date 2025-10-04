<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add indexes for wallet performance optimization
        Schema::table('transactions', function (Blueprint $table) {
            // Index for wallet_id lookups
            $table->index(['wallet_id', 'created_at'], 'idx_wallet_created');
            $table->index(['payable_type', 'payable_id'], 'idx_payable');
            $table->index(['type', 'confirmed'], 'idx_type_confirmed');
            $table->index(['seamless_transaction_id'], 'idx_seamless_tx');
            $table->index(['wager_id'], 'idx_wager_id');
        });

        // Add indexes for wallets table
        Schema::table('wallets', function (Blueprint $table) {
            $table->index(['holder_type', 'holder_id'], 'idx_holder');
            $table->index(['slug'], 'idx_slug');
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_wallet_created');
            $table->dropIndex('idx_payable');
            $table->dropIndex('idx_type_confirmed');
            $table->dropIndex('idx_seamless_tx');
            $table->dropIndex('idx_wager_id');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex('idx_holder');
            $table->dropIndex('idx_slug');
        });
    }
};
