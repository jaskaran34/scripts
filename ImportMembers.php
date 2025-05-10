<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;


class ImportMembers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-members';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
{
    $csvPath = public_path('myukafa missing.csv');
    $token = '12589|9S2tfmDr5bHOkB0MqUrxYgdAw6sVHD7nCkERJGXW2ac5206f'; 

    if (!file_exists($csvPath)) {
        $this->error("CSV file not found: $csvPath");
        return 1;
    }

    $file = fopen($csvPath, 'r');
    $headers = fgetcsv($file);

    while (($row = fgetcsv($file)) !== false) {
        $data = array_map('trim', array_combine($headers, $row));

        $fullName = $data['firstname'] . ' ' . $data['lastname'];
        $mobile = preg_replace('/\D/', '', $data['mobile_number']);
        $phonePrefix = '+974';
        $phone = substr($mobile, -8); // assuming Qatar numbers are 8 digits
        $customerId = $data['customer_id'];
        $defaultBirthday = '1990-01-01';

        $payload = [
            'email'            => $data['email'],
            'name'             => $fullName,
            'phone'            => $phone,
            'phone_prefix'     => $phonePrefix,
            'partner_id'       => '248216521760768',
            'birthday'         => $defaultBirthday,
            'anniversary_date' => null,
            'meta'             => json_encode(['customer_id' => $customerId]),
        ];

        

        $response = Http::withToken($token)
            ->post('https://gocml861g1.execute-api.eu-north-1.amazonaws.com/prod/partner/register', $payload);

        if ($response->successful()) {
            $this->info("✅ Imported: {$data['email']}");
        } else {
            $this->error("❌ Failed for {$data['email']}: " . $response->body());
        }
    }

    fclose($file);
    return 0;
}
}
