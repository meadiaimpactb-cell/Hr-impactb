<?php

namespace App\Jobs;

use App\Services\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued push delivery for an in-app notification (no-op when push is disabled). */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $userIds, public string $title, public ?string $body, public array $data = []) {}

    public function handle(): void
    {
        PushService::send($this->userIds, $this->title, $this->body, $this->data);
    }
}
