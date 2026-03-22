<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic dashboard update event.
 *
 * Broadcasts on the public 'dashboard' channel.
 * All public properties are sent as the payload.
 *
 * Usage:
 *   broadcast(new DashboardEvent('milk_purchase', 'approved'));
 *   broadcast(new DashboardEvent('milk_purchase', 'bulk_approved', ['count' => 3]));
 */
class DashboardEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $scope;
    public string $action;
    public array  $meta;

    public function __construct(string $scope, string $action, array $meta = [])
    {
        $this->scope  = $scope;
        $this->action = $action;
        $this->meta   = $meta;
    }

    public function broadcastOn(): array
    {
        return [new Channel('dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }
}
