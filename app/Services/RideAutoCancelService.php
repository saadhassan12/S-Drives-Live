<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\CancelRide;
use App\Models\ChatRoom;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RideAutoCancelService
{
    public const TIMEOUT_MINUTES = 5;

    /** Rides still at 'requested' (vehicle not chosen) are abandoned after this long. */
    public const REQUESTED_TIMEOUT_MINUTES = 30;

    /** @var list<string> */
    public const PENDING_STATUSES = ['requested', 'in_progress'];

    public function cancelExpiredRides(?int $timeoutMinutes = null): int
    {
        $timeoutMinutes = $timeoutMinutes ?? self::TIMEOUT_MINUTES;

        // in_progress = drivers are being searched: timeout counts from the last passenger activity.
        // requested = passenger has not chosen a vehicle yet: no search running, so wait longer.
        $cutoffs = [
            'in_progress' => now()->subMinutes($timeoutMinutes),
            'requested' => now()->subMinutes(max($timeoutMinutes, self::REQUESTED_TIMEOUT_MINUTES)),
        ];

        $cancelled = 0;

        foreach ($cutoffs as $status => $cutoff) {
            $this->withoutDriver(Ride::query())
                ->where('status', $status)
                ->where('updated_at', '<=', $cutoff)
                ->orderBy('id')
                ->chunkById(50, function ($rides) use (&$cancelled, $timeoutMinutes, $cutoff) {
                    foreach ($rides as $ride) {
                        if ($this->cancelRide($ride, $timeoutMinutes, false, $cutoff)) {
                            $cancelled++;
                        }
                    }
                });
        }

        if ($cancelled > 0) {
            refresh_all_drivers_list('ride_canceled', [
                'status' => 'canceled',
                'count' => $cancelled,
            ]);
        }

        return $cancelled;
    }

    /** A ride a driver has accepted is never auto-canceled. */
    private function withoutDriver($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('driver_id')->orWhere('driver_id', 0);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('bids')
                    ->whereColumn('bids.ride_id', 'rides.id')
                    ->where('bids.status', 'accepted');
            });
    }

    public function cancelRide(Ride $ride, ?int $timeoutMinutes = null, bool $refreshDrivers = true, $cutoff = null): bool
    {
        $timeoutMinutes = $timeoutMinutes ?? self::TIMEOUT_MINUTES;

        if (! in_array($ride->status, self::PENDING_STATUSES, true)) {
            return false;
        }

        // Cancel in one query against the live row, so a ride a driver accepted
        // (or the passenger just re-sent) a moment ago is never canceled.
        $canceled = $this->withoutDriver(Ride::query())
            ->where('rides.id', $ride->id)
            ->whereIn('status', self::PENDING_STATUSES)
            ->when($cutoff, fn ($query) => $query->where('updated_at', '<=', $cutoff))
            ->update(['status' => 'canceled', 'updated_at' => now()]);

        if ($canceled === 0) {
            return false;
        }

        $ride->refresh();

        $reason = "Auto-canceled: no driver accepted within {$timeoutMinutes} minutes.";

        CancelRide::create([
            'ride_id' => $ride->id,
            'user_id' => $ride->user_id,
            'reason' => $reason,
            'canceled_by' => 'passenger',
        ]);

        Bid::where('ride_id', $ride->id)
            ->where('status', 'pending')
            ->delete();

        ChatRoom::where('ride_id', $ride->id)->update([
            'status' => 'closed',
            'ended_at' => now(),
        ]);

        $passenger = User::find($ride->user_id);
        $shouldNotify = $ride->created_at && $ride->created_at->gte(now()->subHour());

        if ($passenger && $shouldNotify) {
            send_user_push_notification(
                $passenger,
                'Ride Canceled',
                'Your ride was canceled because no driver accepted within 5 minutes.'
            );

            notify_passenger_ride_update($passenger->id, [
                'ride_id' => $ride->id,
                'canceled_by' => 'system',
                'reason' => $reason,
            ], 'canceled');
        }

        if ($refreshDrivers) {
            refresh_all_drivers_list('ride_canceled', [
                'ride_id' => $ride->id,
                'status' => 'canceled',
            ]);
        }

        Log::info('Ride auto-canceled due to timeout', [
            'ride_id' => $ride->id,
            'user_id' => $ride->user_id,
            'timeout_minutes' => $timeoutMinutes,
        ]);

        return true;
    }

    public function findExpiredRides(?int $timeoutMinutes = null): Collection
    {
        $timeoutMinutes = $timeoutMinutes ?? self::TIMEOUT_MINUTES;

        return $this->withoutDriver(Ride::query())
            ->where(function ($q) use ($timeoutMinutes) {
                $q->where(fn ($w) => $w->where('status', 'in_progress')
                    ->where('updated_at', '<=', now()->subMinutes($timeoutMinutes)))
                    ->orWhere(fn ($w) => $w->where('status', 'requested')
                        ->where('updated_at', '<=', now()->subMinutes(max($timeoutMinutes, self::REQUESTED_TIMEOUT_MINUTES))));
            })
            ->get();
    }
}
