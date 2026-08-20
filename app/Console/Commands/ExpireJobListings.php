<?php

namespace App\Console\Commands;

use App\Enums\JobListingStatus;
use App\Models\JobListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The clock on job listings.
 *
 * A board full of jobs that closed last month is worse than a small board: a
 * worker who spends their last airtime applying for a post that was filled in
 * March learns not to trust the place. Expiring on the deadline the employer
 * themselves set is the cheapest way to keep it honest.
 *
 * Only listings that are still `open` move. One the employer closed or marked
 * filled keeps its own outcome — a job that was filled did not expire, and
 * conflating the two would make the board's own statistics meaningless.
 */
class ExpireJobListings extends Command
{
    protected $signature = 'jobs:expire';

    protected $description = 'Close job listings whose application deadline has passed';

    public function handle(): int
    {
        $lapsed = JobListing::query()->lapsed()->pluck('id');

        if ($lapsed->isEmpty()) {
            $this->info('Nothing has lapsed.');

            return self::SUCCESS;
        }

        DB::table('job_listings')
            ->whereIn('id', $lapsed)
            ->update([
                'status' => JobListingStatus::Expired->value,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->info(sprintf('%d listing(s) expired.', $lapsed->count()));

        return self::SUCCESS;
    }
}
