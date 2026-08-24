<?php

namespace App\Console\Commands;

use App\Models\Mockup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneMockups extends Command
{
    protected $signature = 'mockups:prune {--days=30 : Remove mockups older than this many days}';

    protected $description = 'Remove expired mockup renders and customer artwork';

    public function handle(): int
    {
        $expired = Mockup::where('created_at', '<', now()->subDays((int) $this->option('days')))->get();
        $designHashes = $expired->pluck('design_hash')->unique();

        foreach ($expired as $mockup) {
            if ($mockup->path) {
                Storage::disk('public')->delete($mockup->path);
            }
        }

        Mockup::whereKey($expired->modelKeys())->delete();

        foreach ($designHashes as $designHash) {
            if (! Mockup::where('design_hash', $designHash)->exists()) {
                Storage::disk('local')->delete(Mockup::designPathFor($designHash));
            }
        }

        $this->info("Pruned {$expired->count()} mockups.");

        return self::SUCCESS;
    }
}
