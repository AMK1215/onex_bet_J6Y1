<?php

namespace App\Http\Controllers\Api\V1\gplus\Webhook;

use App\Enums\SeamlessWalletCode;
use App\Http\Controllers\Controller;
use App\Models\PlaceBet;
use App\Models\PushBet;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\OptimizedGameTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PushBetDataController extends Controller
{
    public function pushBetData(Request $request, OptimizedGameTransactionService $gameService)
    {
        // Log::info('Push Bet Data API Request', ['request' => $request->all()]);

        try {
            $request->validate([
                'operator_code' => 'required|string',
                'wagers' => 'required|array',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Push Bet Data API Validation Failed', ['errors' => $e->errors()]);

            return ApiResponseService::error(
                SeamlessWalletCode::InternalServerError,
                'Validation failed',
                $e->errors()
            );
        }

        $secretKey = Config::get('seamless_key.secret_key');
        $expectedSign = md5(
            $request->operator_code.
            $request->request_time.
            'pushbetdata'.
            $secretKey
        );

        if (!empty($request->sign)) {
            if (strtolower($request->sign) !== strtolower($expectedSign)) {
                Log::warning('Push Bet Data Invalid Signature', ['provided' => $request->sign, 'expected' => $expectedSign]);
                return response()->json([
                    'code' => SeamlessWalletCode::InvalidSignature->value,
                    'message' => 'Invalid signature',
                ]);
            }
        }

        // Batch process transactions for better performance
        $transactions = [];
        $users = [];

        foreach ($request->wagers as $tx) {
            $memberAccount = $tx['member_account'] ?? null;
            
            if (!isset($users[$memberAccount])) {
            $user = User::where('user_name', $memberAccount)->first();
                if (!$user) {
                Log::warning('Member not found for pushBetData', ['member_account' => $memberAccount, 'transaction' => $tx]);
                return response()->json([
                    'code' => SeamlessWalletCode::MemberNotExist->value,
                    'message' => 'Member not found',
                ]);
                }
                $users[$memberAccount] = $user;
            }

            $wagerCode = $tx['wager_code'] ?? null;
            if (!$wagerCode) {
                Log::warning('Transaction missing wager_code in pushBetData', ['tx' => $tx]);
                continue;
            }

            // Determine transaction type and amount
            $wagerStatus = $tx['wager_status'] ?? '';
            $betAmount = (float) ($tx['bet_amount'] ?? 0);
            $prizeAmount = (float) ($tx['prize_amount'] ?? 0);

            // Add bet transaction
            if ($betAmount > 0) {
                $transactions[] = [
                    'user' => $users[$memberAccount],
                    'amount' => $betAmount,
                    'type' => 'bet',
                    'meta' => [
                        'seamless_transaction_id' => $tx['id'] ?? null,
                        'wager_code' => $wagerCode,
                        'product_code' => $tx['product_code'] ?? 0,
                        'game_code' => $tx['game_code'] ?? '',
                        'channel_code' => $tx['channel_code'] ?? '',
                        'raw_payload' => $tx,
                    ]
                ];
            }

            // Add win transaction if settled
            if ($wagerStatus === 'SETTLED' && $prizeAmount > 0) {
                $transactions[] = [
                    'user' => $users[$memberAccount],
                    'amount' => $prizeAmount,
                    'type' => 'win',
                    'meta' => [
                        'seamless_transaction_id' => $tx['id'] ?? null,
                        'wager_code' => $wagerCode,
                        'product_code' => $tx['product_code'] ?? 0,
                        'game_type' => $tx['game_type'] ?? 'SLOT',
                        'action' => 'SETTLED',
                    ]
                ];
            }

            // Still update PushBet table for record keeping
            $this->updatePushBetRecord($tx, $wagerCode);
        }

        // Process all transactions in batch for better performance
        if (!empty($transactions)) {
            try {
                $gameService->batchProcessGameTransactions($transactions);
            } catch (\Exception $e) {
                Log::error('Batch transaction processing failed: ' . $e->getMessage());
                return response()->json([
                    'code' => SeamlessWalletCode::InternalServerError->value,
                    'message' => 'Transaction processing failed',
                ]);
            }
        }

        return response()->json([
            'code' => SeamlessWalletCode::Success->value,
            'message' => '',
        ]);
    }

    /**
     * Update PushBet record (kept for compatibility)
     */
    private function updatePushBetRecord(array $tx, string $wagerCode): void
    {
        $pushBet = PushBet::where('wager_code', $wagerCode)->first();

        $data = [
                    'member_account'      => $tx['member_account'] ?? '',
                    'currency'            => $tx['currency'] ?? '',
                    'product_code'        => $tx['product_code'] ?? 0,
                    'game_code'           => $tx['game_code'] ?? '',
                    'game_type'           => $tx['game_type'] ?? '',
                    'wager_code'          => $tx['wager_code'] ?? '',
                    'wager_type'          => $tx['wager_type'] ?? '',
                    'wager_status'        => $tx['wager_status'] ?? '',
                    'bet_amount'          => $tx['bet_amount'] ?? 0,
                    'valid_bet_amount'    => $tx['valid_bet_amount'] ?? 0,
                    'prize_amount'        => $tx['prize_amount'] ?? 0,
                    'tip_amount'          => $tx['tip_amount'] ?? 0,
                    'created_at_provider' => (isset($tx['created_at']) && is_numeric($tx['created_at'])) ? now()->setTimestamp($tx['created_at']) : null,
                    'settled_at'          => (isset($tx['settled_at']) && is_numeric($tx['settled_at'])) ? now()->setTimestamp($tx['settled_at']) : null,
                    'meta'                => json_encode($tx),
        ];

        if ($pushBet) {
            $pushBet->update($data);
        } else {
            PushBet::create($data);
        }
    }
}
