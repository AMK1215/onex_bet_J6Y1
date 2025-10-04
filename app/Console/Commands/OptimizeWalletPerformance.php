<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class OptimizeWalletPerformance extends Command
{
    protected $signature = 'wallet:optimize {--force : Force optimization without confirmation}';
    protected $description = 'Optimize wallet performance by creating indexes and clearing caches';

    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will create database indexes and clear caches. Continue?')) {
                $this->info('Operation cancelled.');
                return;
            }
        }

        $this->info('Starting wallet performance optimization...');

        // Create indexes
        $this->createIndexes();
        
        // Clear caches
        $this->clearCaches();
        
        // Optimize tables
        $this->optimizeTables();

        $this->info('Wallet performance optimization completed!');
    }

    private function createIndexes()
    {
        $this->info('Creating database indexes...');

        try {
            // Create indexes for transactions table
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_transactions_wallet_created ON transactions (wallet_id, created_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_transactions_payable ON transactions (payable_type, payable_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_transactions_type_confirmed ON transactions (type, confirmed)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_transactions_seamless_tx ON transactions (seamless_transaction_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_transactions_wager_id ON transactions (wager_id)');

            // Create indexes for wallets table
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wallets_holder ON wallets (holder_type, holder_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wallets_slug ON wallets (slug)');

            $this->info('✓ Database indexes created successfully');
        } catch (\Exception $e) {
            $this->error('Failed to create indexes: ' . $e->getMessage());
        }
    }

    private function clearCaches()
    {
        $this->info('Clearing wallet caches...');

        try {
            // Clear wallet balance caches
            $keys = Cache::store('redis')->getRedis()->keys('*wallet_balance_*');
            if (!empty($keys)) {
                Cache::store('redis')->getRedis()->del($keys);
            }

            // Clear game balance caches
            $keys = Cache::store('redis')->getRedis()->keys('*game_balance_*');
            if (!empty($keys)) {
                Cache::store('redis')->getRedis()->del($keys);
            }

            $this->info('✓ Wallet caches cleared successfully');
        } catch (\Exception $e) {
            $this->error('Failed to clear caches: ' . $e->getMessage());
        }
    }

    private function optimizeTables()
    {
        $this->info('Optimizing database tables...');

        try {
            // Analyze tables for better query planning
            DB::statement('ANALYZE transactions');
            DB::statement('ANALYZE wallets');
            DB::statement('ANALYZE users');

            $this->info('✓ Database tables optimized successfully');
        } catch (\Exception $e) {
            $this->error('Failed to optimize tables: ' . $e->getMessage());
        }
    }
}
