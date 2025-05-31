<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use App\Models\Member;

class ImportLoyaltyTransactions extends Command
{
    protected $signature = 'balance';
    protected $description = 'Read CSV and post loyalty transactions for eligible members';

    public function handle()
    {
        $token = '669214|BS9oP7jmqRpZhA5e5LbA0EswaRAL4Qet4pL2L8ca66b8f3c0';
        $integrationId = '250827177766912';
        $csvPath = public_path('final_csv_up.csv');

        if (!file_exists($csvPath)) {
            $this->error("❌ File not found: $csvPath");
            return;
        }

        $file = fopen($csvPath, 'r');
        $headers = fgetcsv($file, 1000, ',');
        $count = 0;

        while (($row = fgetcsv($file, 1000, ',')) !== false) {
            
            $data = array_combine($headers, $row);
            $mobile = trim($data['MOBILE'] ?? '');
            $points = (float) trim($data['LOYALITY POINTS'] ?? 0) * 100;

            if (!$mobile ) {
                continue;
            }

            $member = Member::where('phone', $mobile)->first();

            if (!$member) {
                $this->warn("Member not found for mobile: $mobile");
                continue;
            }
            $amount = ceil($points - 500);
            $orderId = 'OLD_' . Str::upper(Str::random(12));
            $url = "https://gocml861g1.execute-api.eu-north-1.amazonaws.com/prod/partner/cards/879-645-606-742/{$member->unique_identifier}/transactions/purchases";

            $payload = [
                'purchase_amount' => (string) $amount,
                'order_id' => $orderId,
                'integration_id' => $integrationId,
            ];

            $response = Http::withToken($token)->post($url, $payload);

            if ($response->successful()) {
                $this->info("✅ Added transaction for {$mobile} (Amount: $amount)");
                $count++;
            } else {
                $this->error("❌ Failed for {$mobile}. Status: " . $response->status() . " | Response: " . $response->body());
            }

            usleep(200000); // 0.2s sleep to avoid overwhelming the API
        }

        fclose($file);

        $this->info("🎉 Completed: $count transactions processed.");
    }
}
