<?php

// Test script to verify withdraw endpoint handles invalid signatures properly
// This simulates the test case that was failing

$url = 'https://ag.onexbetmm.site/api/v1/api/seamless/withdraw';

$data = [
    "batch_requests" => [
        [
            "game_type" => "SLOT",
            "member_account" => "PLAYER0101",
            "product_code" => 1006,
            "transactions" => [
                [
                    "action" => "BET",
                    "amount" => "-10",
                    "bet_amount" => "10",
                    "game_code" => "testgame001",
                    "id" => "1759552423353_1",
                    "payload" => [
                        "amount" => 10,
                        "provider_tx_id" => "1759552423353",
                        "provider_username" => "test_provider_username123",
                        "roundDetails" => "Spin",
                        "username" => "testusername"
                    ],
                    "prize_amount" => "0",
                    "round_id" => "testRoundID_1759552423353",
                    "settled_at" => 0,
                    "tip_amount" => "0",
                    "valid_bet_amount" => "10",
                    "wager_code" => "1759552423353",
                    "wager_status" => "BET"
                ]
            ]
        ]
    ],
    "currency" => "MMK",
    "operator_code" => "J6Y1",
    "request_time" => "1759489243",
    "sign" => "invalid_signature_should_fail" // This is intentionally invalid
];

$options = [
    'http' => [
        'header' => "Content-type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($data)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === FALSE) {
    echo "Error: Failed to make request\n";
} else {
    echo "Response: " . $result . "\n";
    
    $response = json_decode($result, true);
    if (isset($response['code'])) {
        echo "Response Code: " . $response['code'] . "\n";
        echo "Response Message: " . ($response['message'] ?? 'No message') . "\n";
        
        // Check if it's the expected error code for invalid signature
        if ($response['code'] == 1004) { // 1004 is InvalidSignature
            echo "✅ SUCCESS: Invalid signature properly handled\n";
        } else {
            echo "❌ FAIL: Unexpected response code. Expected 1004 (InvalidSignature), got " . $response['code'] . "\n";
        }
    } else {
        echo "❌ FAIL: No response code in response\n";
    }
}
