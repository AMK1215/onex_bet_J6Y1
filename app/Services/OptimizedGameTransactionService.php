<?php

namespace App\Services;

use App\Enums\TransactionName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OptimizedGameTransactionService
{
    private const CACHE_TTL = 60; // 1 minute for game operations
    private const BALANCE_CACHE_PREFIX = 'game_balance_';

    /**
     * Fast game bet processing with minimal database operations
     */
    public function processGameBet(User $user, float $betAmount, array $gameData): array
    {
        $wallet = $user->wallet;
        if (!$wallet) {
            throw new \Exception('User wallet not found');
        }

        // Use direct database operations for better performance
        return DB::transaction(function () use ($user, $wallet, $betAmount, $gameData) {
            // Check balance with optimized query
            $currentBalance = $this->getOptimizedBalance($wallet->id);
            
            if ($currentBalance < $betAmount) {
                throw new \Exception('Insufficient balance');
            }

            // Create bet transaction
            $betTransaction = $this->createTransaction(
                $wallet->id,
                -$betAmount,
                TransactionName::Settled,
                array_merge($gameData, [
                    'action_type' => 'BET',
                    'opening_balance' => $currentBalance,
                    'target_user_id' => $user->id,
                ])
            );

            // Clear balance cache
            $this->clearBalanceCache($user->id);

            return [
                'transaction_id' => $betTransaction->id,
                'new_balance' => $currentBalance - $betAmount,
                'bet_amount' => $betAmount,
            ];
        });
    }

    /**
     * Fast game win processing
     */
    public function processGameWin(User $user, float $winAmount, array $gameData): array
    {
        $wallet = $user->wallet;
        if (!$wallet) {
            throw new \Exception('User wallet not found');
        }

        return DB::transaction(function () use ($user, $wallet, $winAmount, $gameData) {
            $currentBalance = $this->getOptimizedBalance($wallet->id);

            // Create win transaction
            $winTransaction = $this->createTransaction(
                $wallet->id,
                $winAmount,
                TransactionName::Deposit,
                array_merge($gameData, [
                    'action' => 'SETTLED',
                    'opening_balance' => $currentBalance,
                    'target_user_id' => $user->id,
                    'from_admin' => $gameData['from_admin'] ?? null,
                ])
            );

            // Clear balance cache
            $this->clearBalanceCache($user->id);

            return [
                'transaction_id' => $winTransaction->id,
                'new_balance' => $currentBalance + $winAmount,
                'win_amount' => $winAmount,
            ];
        });
    }

    /**
     * Batch process multiple game transactions
     */
    public function batchProcessGameTransactions(array $transactions): array
    {
        return DB::transaction(function () use ($transactions) {
            $results = [];
            $balanceCache = [];

            foreach ($transactions as $transaction) {
                $user = $transaction['user'];
                $wallet = $user->wallet;
                
                if (!$wallet) {
                    continue;
                }

                $walletId = $wallet->id;
                
                // Use cached balance if available
                if (!isset($balanceCache[$walletId])) {
                    $balanceCache[$walletId] = $this->getOptimizedBalance($walletId);
                }

                $currentBalance = $balanceCache[$walletId];
                $amount = $transaction['amount'];
                $type = $transaction['type']; // 'bet' or 'win'

                if ($type === 'bet' && $currentBalance < $amount) {
                    throw new \Exception("Insufficient balance for user {$user->id}");
                }

                // Create transaction
                $dbTransaction = $this->createTransaction(
                    $walletId,
                    $type === 'bet' ? -$amount : $amount,
                    $type === 'bet' ? TransactionName::Settled : TransactionName::Deposit,
                    array_merge($transaction['meta'], [
                        'opening_balance' => $currentBalance,
                        'target_user_id' => $user->id,
                    ])
                );

                // Update cached balance
                $balanceCache[$walletId] += ($type === 'bet' ? -$amount : $amount);

                $results[] = [
                    'user_id' => $user->id,
                    'transaction_id' => $dbTransaction->id,
                    'amount' => $amount,
                    'type' => $type,
                    'new_balance' => $balanceCache[$walletId],
                ];
            }

            // Clear all affected user caches
            foreach ($transactions as $transaction) {
                $this->clearBalanceCache($transaction['user']->id);
            }

            return $results;
        });
    }

    /**
     * Get optimized balance using direct database query
     */
    private function getOptimizedBalance(int $walletId): float
    {
        $cacheKey = self::BALANCE_CACHE_PREFIX . $walletId;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($walletId) {
            return (float) DB::table('transactions')
                ->where('wallet_id', $walletId)
                ->where('confirmed', true)
                ->sum('amount');
        });
    }

    /**
     * Create transaction with minimal overhead
     */
    private function createTransaction(int $walletId, float $amount, TransactionName $transactionName, array $meta)
    {
        $uuid = \Illuminate\Support\Str::uuid();
        
        return DB::table('transactions')->insertGetId([
            'payable_type' => 'App\Models\User',
            'payable_id' => $meta['target_user_id'] ?? null,
            'wallet_id' => $walletId,
            'type' => $amount > 0 ? 'deposit' : 'withdraw',
            'amount' => $amount,
            'confirmed' => true,
            'meta' => json_encode($meta),
            'uuid' => $uuid,
            'name' => $transactionName->value,
            'target_user_id' => $meta['target_user_id'] ?? null,
            'is_report_generated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Clear balance cache for user
     */
    private function clearBalanceCache(int $userId): void
    {
        $user = User::find($userId);
        if ($user && $user->wallet) {
            $cacheKey = self::BALANCE_CACHE_PREFIX . $user->wallet->id;
            Cache::forget($cacheKey);
        }
    }

    /**
     * Get user balance for game operations (cached)
     */
    public function getGameBalance(User $user): float
    {
        $wallet = $user->wallet;
        if (!$wallet) {
            return 0.0;
        }

        return $this->getOptimizedBalance($wallet->id);
    }

    /**
     * Bulk update balances for multiple users (for reporting)
     */
    public function bulkUpdateBalances(array $userIds): void
    {
        $wallets = DB::table('wallets')
            ->whereIn('holder_id', $userIds)
            ->where('holder_type', 'App\Models\User')
            ->get();

        foreach ($wallets as $wallet) {
            $cacheKey = self::BALANCE_CACHE_PREFIX . $wallet->id;
            Cache::forget($cacheKey);
        }
    }
}
