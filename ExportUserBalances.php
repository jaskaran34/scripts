<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Member;

class ExportUserBalances extends Command
{
    protected $signature = 'export:user-balances';
    protected $description = 'Fetch user info from API and create CSV';

    public function handle()
{
    $fetchToken = 'c9d6w2uituto1cl8qkcbc890squj66jj'; // for fetching balance
    $purchaseToken = '12589|9S2tfmDr5bHOkB0MqUrxYgdAw6sVHD7nCkERJGXW2ac5206f'; // for posting purchase

    $fetchApiUrl = 'https://admin.js.qa/rest/V1/wac/customer/info';
    $purchaseApiBase = 'https://gocml861g1.execute-api.eu-north-1.amazonaws.com/prod/partner/cards';

    $integrationId = '250827177766912';
    $cardUid = '879-645-606-742';

    $members = Member::whereDate('created_at', '2025-05-11')->whereNotNull('meta')->get();

    foreach ($members as $member) {
        if (!$member->email || !$member->unique_identifier) {
            continue;
        }

        $response = Http::withToken($fetchToken)
            ->post($fetchApiUrl, ['email' => $member->email]);

        if (!$response->successful()) {
            $this->error("❌ API failed for email: {$member->email}");
            continue;
        }

        $data = $response->json();
        if (empty($data[0])) {
            $this->warn("⚠️ No customer data for: {$member->email}");
            continue;
        }

        $customer = $data[0];
        $balance = (int)($customer['mukafa_points'] ?? 0);

        if ($balance <= 500) {
            $this->info("Skipped {$member->email}: balance = {$balance} <= 500");
            continue;
        }

        $purchaseAmount = $balance - 500;
        $orderId = 'ORD-' . strtoupper(uniqid(substr($member->email, 0, 3))) . '-' . now()->format('YmdHis');

        $purchaseUrl = "{$purchaseApiBase}/{$cardUid}/{$member->unique_identifier}/transactions/purchases";

        $purchasePayload = [
            'purchase_amount' => (string)$purchaseAmount,
            'order_id' => $orderId,
            'integration_id' => $integrationId,
        ];

        $purchaseResponse = Http::withToken($purchaseToken)
            ->post($purchaseUrl, $purchasePayload);

        if ($purchaseResponse->successful()) {
            $this->info("✅ Purchase added for {$member->email}, amount: {$purchaseAmount}");
        } else {
            $this->error("❌ Purchase API failed for {$member->email}: " . $purchaseResponse->body());
        }
    }

    $this->info("🎉 All users processed.");
}

}