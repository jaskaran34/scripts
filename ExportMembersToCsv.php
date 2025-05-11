<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Member;
use Illuminate\Support\Facades\Http;

class ExportMembersToCsv extends Command
{
    protected $signature = 'app:export-members-to-csv';
    protected $description = 'Export member details with balance to a CSV file';

    public function handle()
    {
        $members = Member::all();
        $csvFileName = 'members_export_' . now()->format('Y_m_d') . '.csv';
        $csvPath = storage_path("app/{$csvFileName}");

        $token = '12593|qHhV8kt8i023qFoDcwEBfUvdL3xMe9EPdXjpsZcAcfebac44'; // Use env in production!

        $file = fopen($csvPath, 'w');

        // CSV Headers
        fputcsv($file, [
            'name',
            'mukafa_number',
            'email',
            'extension',
            'phone',
            'jumbo_cust_id',
            'balance',
        ]);

        foreach ($members as $member) {
            $apiUrl = "https://gocml861g1.execute-api.eu-north-1.amazonaws.com/prod/partner/member/{$member->unique_identifier}";

            try {
                $response = Http::withToken($token)
                    ->timeout(10)
                    ->get($apiUrl);

                if ($response->successful()) {
                    $data = $response->json();

                    fputcsv($file, [
                        $data['member']['name'] ?? '',
                        $data['member']['mukafa_number'] ?? '',
                        $data['member']['email'] ?? '',
                        $member->phone_prefix ?? '',
                        $data['member']['phone'] ?? '',
                        $member->jumbo_cust_id ?? '',
                        $data['balance'] ?? 0,
                    ]);

                    $this->info($data['member']['mukafa_number']);
                } else {
                    $this->error("API failed for {$member->unique_identifier}: Status " . $response->status());
                }
            } catch (\Exception $e) {
                $this->error("Exception for {$member->unique_identifier}: " . $e->getMessage());
            }
        }

        fclose($file);

        $this->info("✅ CSV export completed: {$csvPath}");
    }
}
