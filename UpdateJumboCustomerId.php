<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Member;

class UpdateJumboCustomerId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-jumbo-customer-id';

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
        $members = Member::whereDate('created_at', '2025-05-11')->whereNotNull('meta')->get();

        foreach ($members as $member) {
            $meta = json_decode($member->meta, true);

            if (isset($meta['customer_id'])) {
                $member->jumbo_cust_id = $meta['customer_id'];
                $member->save();

                $this->info("Updated Member ID {$member->id} with jumbo_cust_id: {$meta['customer_id']}");
            } else {
                $this->warn("No customer_id found in meta for Member ID {$member->id}");
            }
        }

        $this->info("✔️ Update complete.");
        return 0;
    }
}
