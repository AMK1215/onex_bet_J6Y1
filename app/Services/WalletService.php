<?php

namespace App\Services;

use App\Enums\TransactionName;
use App\Enums\TransactionType;
use App\Models\User;
use Bavix\Wallet\External\Dto\Extra;
use Bavix\Wallet\External\Dto\Option;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const BALANCE_CACHE_PREFIX = 'wallet_balance_';

    public function forceTransfer(User $from, User $to, float $amount, TransactionName $transaction_name, array $meta = [])
    {
        return $this->performTransfer($from, $to, $amount, $transaction_name, $meta, true);
    }

    public function transfer(User $from, User $to, float $amount, TransactionName $transaction_name, array $meta = [])
    {
        return $this->performTransfer($from, $to, $amount, $transaction_name, $meta, false);
    }

    public function deposit(User $user, float $amount, TransactionName $transaction_name, array $meta = [])
    {
        $this->clearBalanceCache($user);
        $user->depositFloat($amount, self::buildDepositMeta($user, $user, $transaction_name, $meta));
    }

    public function withdraw(User $user, float $amount, TransactionName $transaction_name, array $meta = [])
    {
        $this->clearBalanceCache($user);
        $user->withdrawFloat($amount, self::buildDepositMeta($user, $user, $transaction_name, $meta));
    }

    /**
     * Get cached balance for better performance
     */
    public function getCachedBalance(User $user): float
    {
        $cacheKey = self::BALANCE_CACHE_PREFIX . $user->id;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $user->balanceFloat;
        });
    }

    /**
     * Fast balance check without loading full wallet
     */
    public function hasBalance(User $user, float $amount): bool
    {
        $balance = $this->getCachedBalance($user);
        return $balance >= $amount;
    }

    /**
     * Batch operations for multiple transactions
     */
    public function batchDeposit(array $transactions): void
    {
        DB::transaction(function () use ($transactions) {
            foreach ($transactions as $transaction) {
                $user = $transaction['user'];
                $amount = $transaction['amount'];
                $transaction_name = $transaction['transaction_name'];
                $meta = $transaction['meta'] ?? [];

                $this->clearBalanceCache($user);
                $user->depositFloat($amount, self::buildDepositMeta($user, $user, $transaction_name, $meta));
            }
        });
    }

    /**
     * Optimized transfer with reduced database queries
     */
    private function performTransfer(User $from, User $to, float $amount, TransactionName $transaction_name, array $meta, bool $force = false)
    {
        // Clear cache for both users
        $this->clearBalanceCache($from);
        $this->clearBalanceCache($to);

        if ($force) {
            return $from->forceTransferFloat($to, $amount, new Extra(
                deposit: new Option(self::buildTransferMeta($to, $from, $transaction_name, $meta)),
                withdraw: new Option(self::buildTransferMeta($from, $to, $transaction_name, $meta))
            ));
        }

        return $from->transferFloat($to, $amount, new Extra(
            deposit: new Option(self::buildTransferMeta($to, $from, $transaction_name, $meta)),
            withdraw: new Option(self::buildTransferMeta($from, $to, $transaction_name, $meta))
        ));
    }

    /**
     * Clear balance cache for user
     */
    private function clearBalanceCache(User $user): void
    {
        $cacheKey = self::BALANCE_CACHE_PREFIX . $user->id;
        Cache::forget($cacheKey);
    }

    /**
     * Optimized balance calculation for high-frequency operations
     */
    public function getOptimizedBalance(User $user): float
    {
        // For game operations, use direct database query instead of wallet package
        $wallet = $user->wallet;
        if (!$wallet) {
            return 0.0;
        }

        $balance = DB::table('transactions')
            ->where('wallet_id', $wallet->id)
            ->where('confirmed', true)
            ->sum('amount');

        return (float) $balance;
    }

    public static function buildTransferMeta(User $user, User $target_user, TransactionName $transaction_name, array $meta = [])
    {
        return array_merge([
            'name' => $transaction_name,
            'opening_balance' => $user->balanceFloat,
            'target_user_id' => $target_user->id,
        ], $meta);
    }

    public static function buildDepositMeta(User $user, User $target_user, TransactionName $transaction_name, array $meta = [])
    {
        return array_merge([
            'name' => $transaction_name->value,
            'opening_balance' => $user->balanceFloat,
            'target_user_id' => $target_user->id,
        ], $meta);
    }
}
