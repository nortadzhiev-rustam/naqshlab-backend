<?php

namespace App\Jobs;

use App\Enums\MockupStatus;
use App\Models\Mockup;
use App\Services\MockupComposer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateMockup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $mockupId) {}

    public function handle(MockupComposer $composer): void
    {
        $mockup = Mockup::with('template')->find($this->mockupId);

        if (! $mockup || $mockup->status === MockupStatus::Ready) {
            return;
        }

        $designPath = Mockup::designPathFor($mockup->design_hash);

        if (! Storage::disk('local')->exists($designPath)) {
            throw new RuntimeException("Design {$mockup->design_hash} is no longer stored.");
        }

        $result = $composer->render(
            Storage::disk('local')->get($designPath),
            $mockup->template
        );

        $path = "mockups/{$mockup->cache_key}.webp";
        if (! Storage::disk('public')->put($path, $result['contents'])) {
            throw new RuntimeException('Unable to store the rendered mockup.');
        }

        $mockup->update([
            'path' => $path,
            'width' => $result['width'],
            'height' => $result['height'],
            'status' => MockupStatus::Ready,
            'failure_reason' => null,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Mockup generation failed.', [
            'mockup_id' => $this->mockupId,
            'exception' => $e,
        ]);

        Mockup::whereKey($this->mockupId)->update([
            'status' => MockupStatus::Failed,
            'failure_reason' => 'Mockup rendering failed.',
        ]);
    }
}
