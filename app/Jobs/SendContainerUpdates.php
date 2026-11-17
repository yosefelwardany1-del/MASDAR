<?php

namespace App\Jobs;

use App\Models\ContainerEvent;
use App\Services\Shipping\ClientContainerUpdates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the WhatsApp container update for one status event, on the queue worker and only once
 * the status is committed ($afterCommit): a send never slows or rolls back the status.
 */
class SendContainerUpdates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $containerEventId)
    {
        $this->afterCommit();
    }

    public function handle(ClientContainerUpdates $updates): void
    {
        if ($event = ContainerEvent::query()->find($this->containerEventId)) {
            $updates->notify($event);
        }
    }
}
