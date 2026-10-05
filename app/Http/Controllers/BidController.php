<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\ChatRoom;
use App\Models\Ride;
use App\Models\User;
use App\Services\SocketNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BidController extends Controller
{
    public function placeBid(Request $request, $rideId)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'time' => 'required|string',
        ]);

        $rideId = resolve_ride_id($rideId);
        $ride = Ride::findOrFail($rideId);
        $passenger = User::find($ride->user_id);

        $existingBid = Bid::where('ride_id', $rideId)
            ->where('driver_id', auth()->id())
            ->first();

        if ($existingBid) {
            $existingBid->update([
                'amount' => $request->amount,
                'time' => $request->time,
                'status' => 'pending',
                'user_id' => $ride->user_id,
            ]);

            $existingBid->refresh();
            remember_ride_notified_drivers((int) $rideId, [(int) auth()->id()]);

            if ($passenger && $passenger->device_token) {
                send_user_push_notification(
                    $passenger,
                    'New Bid Received',
                    "A driver has updated the bid to {$request->amount} on your ride."
                );
            }

            reset_ride_visibility_after_bid($ride);

            notify_bid_update(
                $passenger->id,
                bid_socket_payload_for_passenger($existingBid, 'updated')
            );

            return apiResponse(
                format_bid_for_passenger($existingBid),
                'Bid updated successfully!'
            );
        }

        $bid = Bid::create([
            'ride_id' => $rideId,
            'driver_id' => auth()->id(),
            'amount' => $request->amount,
            'time' => $request->time,
            'user_id' => $ride->user_id,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        remember_ride_notified_drivers((int) $rideId, [(int) auth()->id()]);

        if ($passenger && $passenger->device_token) {
            send_user_push_notification(
                $passenger,
                'New Bid Received',
                "A driver has placed a bid of {$request->amount} on your ride."
            );
        }

        reset_ride_visibility_after_bid($ride);

        notify_bid_update(
            $passenger->id,
            bid_socket_payload_for_passenger($bid, 'new')
        );

        return apiResponse(
            format_bid_for_passenger($bid),
            'Bid placed successfully!'
        );
    }

    public function acceptBid(Request $request, $rideId, $bid_id)
    {
        $ride = Ride::findOrFail($rideId);
        $bid = Bid::where('ride_id', $rideId)
            ->where('id', $bid_id)
            ->first();

        if (!$bid) {
            return apiResponse(null, 'Bid not found.', 404);
        }

        $bid->update([
            'user_id' => auth()->id(),
            'status' => 'accepted',
        ]);

        $ride->driver_id = $bid->driver_id;
        $ride->bid_id = $bid_id;
        $ride->final_fare = $bid->amount;
        $ride->status = 'accepted';
        $ride->save();

        $chatRoom = ChatRoom::updateOrCreate(
            ['ride_id' => $ride->id],
            [
                'passenger_id' => $ride->user_id,
                'driver_id' => $bid->driver_id,
                'status' => 'active',
                'started_at' => now(),
                'ended_at' => null,
            ]
        );

        Bid::where('ride_id', $rideId)
            ->where('id', '!=', $bid_id)
            ->delete();

        $driver = User::find($bid->driver_id);

        if (!empty($driver) && !empty($driver->device_token)) {
            try {
                send_user_push_notification(
                    $driver,
                    'Bid Accepted',
                    'A Passenger accepted your offer. Get ready for pickup.'
                );
            } catch (\Exception $e) {
                \Log::error('Notification Error: ' . $e->getMessage());
            }
        }

        if ($driver) {
            notify_bid_update($driver->id, [
                'bid_id' => $bid->id,
                'ride_id' => $ride->id,
                'status' => 'accepted',
                'final_fare' => $bid->amount,
                'passenger_id' => $ride->user_id,
                'chat_room_id' => $chatRoom->id,
            ]);
        }

        notify_passenger_ride_update($ride->user_id, [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'driver_name' => $driver->name ?? 'Driver',
            'final_fare' => $bid->amount,
            'chat_room_id' => $chatRoom->id,
        ], 'accepted');

        refresh_all_drivers_list('bid_accepted', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'status' => 'accepted',
        ]);

        SocketNotifier::chatStarted([
            'chat_room_id' => $chatRoom->id,
            'ride_id' => $ride->id,
            'passenger_id' => $ride->user_id,
            'driver_id' => $bid->driver_id,
        ]);

        return apiResponse([
            'bid' => format_bid_for_passenger($bid),
            'chat_room' => $chatRoom,
        ], 'Bid accepted, chat started, and other bids removed successfully!', 200);
    }

    public function rejectBid(Request $request, $rideId, $bid_id)
    {
        Ride::findOrFail($rideId);

        $bid = Bid::where('ride_id', $rideId)
            ->where('id', $bid_id)
            ->first();

        if ($bid) {
            $driver = User::find($bid->driver_id);
            $bid->delete();

            if ($driver && $driver->device_token) {
                send_user_push_notification(
                    $driver,
                    'Bid Rejected',
                    'Your bid for ride was rejected by the passenger.'
                );
            }

            return apiResponse(null, 'Bid rejected and driver notified successfully!');
        }

        return apiResponse(null, 'No bid found to reject.', 404);
    }

    public function getBid(Request $request, $rideId)
    {
        Ride::where('id', $rideId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $oneHundredSeventeenSecondsAgo = Carbon::now()->subSeconds(77);
        Bid::where('ride_id', $rideId)
            ->where('status', 'pending')
            ->where('created_at', '<=', $oneHundredSeventeenSecondsAgo)
            ->delete();

        $bids = Bid::with(['driver', 'vehicles'])
            ->where('ride_id', $rideId)
            ->where('status', 'pending')
            ->get();

        return apiResponse(
            format_bids_for_passenger($bids),
            'All bids for the ride retrieved successfully.'
        );
    }
}
