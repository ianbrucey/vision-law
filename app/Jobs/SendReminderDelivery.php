<?php

namespace App\Jobs;

use App\Models\ReminderDelivery;
use App\Services\Calendaring\DeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendReminderDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $deliveryId,
    ) {}

    public function handle(DeliveryService $service): void
    {
        $delivery = ReminderDelivery::query()->whereKey($this->deliveryId)->first();
        if ($delivery === null) {
            return;
        }

        $service->deliver($delivery);
    }
}
