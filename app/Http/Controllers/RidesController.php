<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Models\CancelRide;
use App\Models\Ride;
use App\Models\Bid;
use App\Models\User;
use App\Models\VehicleCategory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


use Illuminate\Support\Str;

use App\Notifications\FirebasePushNotification;


class RidesController extends Controller
{
    public function calculateDistance($startLat, $startLong, $endLat, $endLong)
    {
        $earthRadius = 6371; // Radius of the earth in km
        $latDistance = deg2rad($endLat - $startLat);
        $lonDistance = deg2rad($endLong - $startLong);
        $a = sin($latDistance / 2) * sin($latDistance / 2) +
            cos(deg2rad($startLat)) * cos(deg2rad($endLat)) *
            sin($lonDistance / 2) * sin($lonDistance / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c; // Distance in km
        return $distance;
    }

    protected function getNearbyBounds(float $latitude, float $longitude, float $distanceKm): array
    {
        $latDelta = $distanceKm / 111.0;
        $lngDelta = $distanceKm / max(0.00001, 111.320 * cos(deg2rad($latitude)));

        return [
            $latitude - $latDelta,
            $latitude + $latDelta,
            $longitude - $lngDelta,
            $longitude + $lngDelta,
        ];
    }
    
    

  public function createBooking(Request $request)
    {
        $request->validate([
            'start_latitude' => 'required|numeric|between:-90,90',
            'start_longitude' => 'required|numeric|between:-180,180',
            'end_latitude' => 'required|numeric|between:-90,90',
            'end_longitude' => 'required|numeric|between:-180,180',
            'start' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        // Check if user is a passenger
        if ($user->role !== 'passenger') {
            return apiResponse(null, 'Only passengers can book a ride', 403);
        }
        $existingRide = Ride::where('user_id', $user->id)
            ->whereIn('status', ['accepted'])
            ->first();

        if ($existingRide) {
            return apiResponse(null, 'You already have an active ride. Complete or cancel it before booking a new one.', 400);
        }

        // Calculate Distance
        $distance = $this->calculateDistance(
            $request->start_latitude,
            $request->start_longitude,
            $request->end_latitude,
            $request->end_longitude
        );
        
        // Get Vehicle Categories and Calculate Fare
     $vehicles = VehicleCategory::leftJoin('fares', 'fares.vehicle_category_id', '=', 'vehicle_categories.id')
    ->select('vehicle_categories.id', 'vehicle_categories.name', 'fares.fare_per_km', 'fares.minimun_rate')
    ->get()
    ->map(function ($vehicle) use ($distance) {
        if ($distance <= 1) {
            $vehicle->calculated_fare = intval($vehicle->minimun_rate);
        } else {
            $extra_distance = $distance - 1;
            $vehicle->calculated_fare = intval($vehicle->minimun_rate + ($extra_distance * $vehicle->fare_per_km));
        }
        return $vehicle;
    });

        // Create New Ride
        $ride = Ride::create([
            'user_id' => $user->id,
            'start_latitude' => $request->start_latitude,
            'start_longitude' => $request->start_longitude,
            'end_latitude' => $request->end_latitude,
            'end_longitude' => $request->end_longitude,
            'estimated_fare' => $vehicles->first()->calculated_fare,
            'start' => $request->start,
            'destination' => $request->destination,
            'status' => 'requested',
            'created_at' => now(),
        ]);

        return apiResponse([
            'ride' => $ride,
            'distance' => $distance,
            'vehicles' => $vehicles,
        ], 'Ride has been created successfully', 201);
    }

public function updateBooking(Request $request, $id)
{
    $request->validate([
        'vehicle_category_id' => 'required|exists:vehicle_categories,id',
    ]);

    $ride = Ride::find($id);
    if (!$ride) {
        return apiResponse(null, 'Ride not found', 404);
    }

    // A ride a driver already accepted must not be reset to searching.
    if (in_array($ride->status, ['accepted', 'driver_reach', 'ride_pick', 'started_ride', 'completed'], true)) {
        return apiResponse($ride, 'Ride is already accepted by a driver.', 200, false);
    }

    // ? Calculate distance
    $distance = $this->calculateDistance(
        $ride->start_latitude,
        $ride->start_longitude,
        $ride->end_latitude,
        $ride->end_longitude
    );

    // ? Get vehicle + fare details (including promo rates)
    $vehicleCategory = VehicleCategory::leftJoin('fares', 'fares.vehicle_category_id', '=', 'vehicle_categories.id')
        ->select(
            'vehicle_categories.id',
            'vehicle_categories.name',
            'fares.fare_per_km',
            'fares.minimun_rate',
            'fares.pro_code_rate',
            'fares.pro_code_minimun_rate'
        )
        ->find($request->input('vehicle_category_id'));

    if (!$vehicleCategory) {
        return apiResponse(null, 'Vehicle category not found', 404);
    }

    // ? Check for promo code (from request or ride)
    $promoCodeToUse = $request->input('promo_code') ?? $ride->promo_code;

    $promo = null;
    if ($promoCodeToUse) {
        $promo = DB::table('promo_codes')
            ->where('code', $promoCodeToUse)
            ->where('is_active', 1)
            ->first();
    }


    // ? Use promo-specific rates if promo code is valid
    if ($promo) {
        $farePerKm = $vehicleCategory->pro_code_rate ?? $vehicleCategory->fare_per_km;
        $minRate = $vehicleCategory->pro_code_minimun_rate ?? $vehicleCategory->minimun_rate;
    } else {
        $farePerKm = $vehicleCategory->fare_per_km;
        $minRate = $vehicleCategory->minimun_rate;
    }

    // ? Base fare calculation
    if ($distance <= 1) {
        $fare = $minRate;
    } else {
        $extraDistance = $distance - 1;
        $fare = $minRate + ($extraDistance * $farePerKm);
    }

    // ? Apply promo type discount
    if ($promo) {
        if ($promo->type === 'fixed') {
            $fare = max(0, $fare - $promo->value);
        } elseif ($promo->type === 'percent') {
            $discount = ($fare * $promo->value) / 100;

            if (!empty($promo->max_discount) && $discount > $promo->max_discount) {
                $discount = $promo->max_discount;
            }

            $fare = max(0, $fare - $discount);

        }

        $ride->promo_code = $promo->code;

    }

    $wasAlreadyActive = $ride->status === 'in_progress';

    // ? Save ride updates
    $ride->vehicle_category_id = $vehicleCategory->id;
    $ride->status = 'in_progress';
    $ride->estimated_fare = round($fare);
    $ride->save();

    if ($wasAlreadyActive) {
        notify_drivers_fare_updated($ride, $ride->estimated_fare);

        return apiResponse(
            $ride,
            'Fare updated successfully, ride re-sent to nearby drivers.',
            200
        );
    }

    $categoryIds = compatible_vehicle_category_ids((int) $vehicleCategory->id);
    $radiusKm = driver_ride_radius_km();
    $drivers = find_nearby_drivers_for_ride(
        (float) $ride->start_latitude,
        (float) $ride->start_longitude,
        $categoryIds,
        $radiusKm
    );

    foreach ($drivers as $driver) {
        send_driver_ride_notification(
            $driver,
            'New Ride Available',
            'A passenger nearby is requesting a ride. Accept now before it\'s gone.'
        );
    }

    $driverIds = $drivers->pluck('id')->toArray();
    if (!empty($driverIds)) {
        notify_drivers_new_ride($driverIds, [
            'ride_id' => $ride->id,
            'start' => $ride->start,
            'destination' => $ride->destination,
            'estimated_fare' => $ride->estimated_fare,
            'distance' => $this->calculateDistance(
                $ride->start_latitude,
                $ride->start_longitude,
                $ride->end_latitude,
                $ride->end_longitude
            ),
            'vehicle_category_id' => $ride->vehicle_category_id,
            'status' => $ride->status,
            'max_radius_km' => $radiusKm,
        ]);
    }

    return apiResponse($ride, 'Vehicle category and fare updated successfully, notifications sent to nearby drivers');
}




    public function getbyid($id)
    {
         $userId = auth()->id();
        $getbyid = Ride::with('user')->where('user_id', auth()->id())->find($id);
        return apiResponse(
            $getbyid,
            'Ride Get By ID  successfully',
        );
    }
    public function most_address()
    {
        $userId = auth()->id();
        $topLocations = Ride::select('start', 'destination', DB::raw('COUNT(*) as count'))
            ->where('user_id', $userId)
            ->groupBy('start', 'destination')
            ->orderByDesc('count')
            ->take(5)
            ->get();
        return apiResponse($topLocations, 'The top 5 most visited locations');
    }

 public function cancelRide(Request $request, $rideId)
{
    $user = auth()->user();

    $request->validate([
        'reason' => 'nullable|string|max:255',
    ]);

    $ride = Ride::find($rideId);

    if (!$ride) {
        return apiResponse(null, 'Ride not found', 404);
    }

    if ($ride->status === 'canceled') {
        return apiResponse(null, 'Ride already canceled', 400);
    }

    $cancelData = CancelRide::create([
        'ride_id'     => $ride->id,
        'user_id'     => $user->id,
        'reason'      => $request->reason,
        'canceled_by' => $user->role === 'driver' ? 'driver' : 'passenger',
    ]);

$ride->status = 'canceled';
$ride->save();

    ChatRoom::where('ride_id', $ride->id)->update([
        'status' => 'closed',
        'ended_at' => now(),
    ]);

    $notifyUser = null;
    if ($user->role === 'passenger') {
        $notifyUser = User::find($ride->passenger_id ?? $ride->user_id);
       
    } else {
        if ($ride->driver_id) {
            $notifyUser = User::find($ride->driver_id);
        }
    }

    $firebaseResponse = null;
    if ($notifyUser && !empty($notifyUser->device_token)) {
        $firebaseResponse = send_user_push_notification(
            $notifyUser,
            'Ride Canceled',
            'Your ride has been canceled. You can request/accept a new ride anytime.'
        );
    }

    // 🚀 REAL-TIME: Notify via socket
    if ($notifyUser) {
        notify_passenger_ride_update($notifyUser->id, [
            'ride_id' => $ride->id,
            'canceled_by' => $user->role,
            'reason' => $request->reason,
        ], 'canceled');
    }

    // 🔄 REFRESH: Auto-refresh all drivers' nearby rides list (remove canceled ride)
    refresh_all_drivers_list('ride_canceled', [
        'ride_id' => $ride->id,
        'status' => 'canceled',
    ]);

    return apiResponse( 'Ride canceled successfully', 200);
}




public function updateBidAmount(Request $request, $ride_id)
{
    $amountChanges = $request->final_fare;
    $ride = Ride::find($ride_id);

    if (!$ride) {
        return apiResponse(null, 'Ride not found', 404);
    }

    notify_drivers_fare_updated($ride, $amountChanges);

    return apiResponse(
        $ride->fresh(),
        'Ride fare updated successfully and nearby drivers notified!',
        200
    );
}


    
  public function getActiveBids($rideId)
    {
        $oldBids = Bid::where('ride_id', $rideId)
            ->where('status', 'pending')
            ->with(['driver', 'vehicles'])
            ->get();

        if ($oldBids->isNotEmpty()) {
            $oneHundredSeventeenSecondsAgo = Carbon::now()->subSeconds(77);
            Bid::where('ride_id', $rideId)
                ->where('status', 'pending')
                ->where('created_at', '<=', $oneHundredSeventeenSecondsAgo)
                ->delete();

            return apiResponse(
                format_bids_for_passenger($oldBids),
                'Old bids retrieved successfully.'
            );
        }

        return apiResponse(null, 'No old bids found.', 200, false);
    }
 public function getAcceptedRidesbydriver(Request $request)
    {
        $rides = Ride::whereIn('status', ['started_ride','in_progress', 'accepted','driver_reach','ride_pick'])
            ->where('user_id', auth()->id())
            ->with(['driver', 'user_pe', 'vehicles', 'ratings', 'chatRoom'])
            ->get();
        return apiResponse($rides, 'Accepted rides with assigned drivers retrieved successfully.');
    }
    
public function generateShareLinks($rideId)
{
    $ride = Ride::where('id', $rideId)
        ->where('status', 'accepted')
        ->first();

    if (!$ride) {
        return response()->json(['message' => 'Ride not found or not accepted.'], 404);
    }

    $urls = [];

    // 20 unique random URLs generate karo
    for ($i = 1; $i <= 20; $i++) {
        $token = \Illuminate\Support\Str::random(20);
        $urls[] = url('/share/ride/' . $ride->id . '?token=' . $token);
    }

    return response()->json([
        'ride_id' => $ride->id,
        'share_urls' => $urls
    ]);
}
    
    
    public function getbyuser(Request $request)
    {
        $user = auth()->user();

        $ridesQuery = Ride::query()
            ->whereIn('status', ['completed', 'canceled'])
            ->with(['driver', 'user_pe', 'vehicles', 'ratings']);

        if ($user->role === 'driver') {
            $ridesQuery->where('driver_id', $user->id);
        } else {
            $ridesQuery->where('user_id', $user->id);
        }

        $rides = $ridesQuery->orderByDesc('updated_at')->get();

        return apiResponse($rides, 'Ride history retrieved successfully.');
    }

public function applyPromoCode(Request $request, $rideId)
{
    $request->validate([
        'promo_code' => 'required|string|max:50',
    ]);

    // ? Find ride
    $ride = DB::table('rides')->where('id', $rideId)->first();

    if (!$ride) {
        return apiResponse(null, 'Ride not found', 404);
    }

    // ? Validate promo code
    $promo = DB::table('promo_codes')
        ->where('code', $request->promo_code)
        ->where('is_active', 1)
        ->first();

    if (!$promo) {
        return apiResponse(null, 'Invalid or inactive promo code.', 400);
    }

    // ? Calculate ride distance
    $distance = $this->calculateDistance(
        $ride->start_latitude,
        $ride->start_longitude,
        $ride->end_latitude,
        $ride->end_longitude
    );

    // ? Get all vehicle categories with fare details
    $vehicles = DB::table('vehicle_categories')
        ->leftJoin('fares', 'fares.vehicle_category_id', '=', 'vehicle_categories.id')
        ->select(
            'vehicle_categories.id',
            'vehicle_categories.name',
            'fares.fare_per_km',
            'fares.minimun_rate',
            'fares.pro_code_rate',
            'fares.pro_code_minimun_rate'
        )
        ->get()
        ->map(function ($vehicle) use ($distance, $promo) {
            // Use promo-specific rates if available
            $fare_per_km = $vehicle->pro_code_rate ?? $vehicle->fare_per_km;
            $min_rate = $vehicle->pro_code_minimun_rate ?? $vehicle->minimun_rate;

            // ? Base fare calculation
            if ($distance <= 1) {
                $fare = $min_rate;
            } else {
                $extraDistance = $distance - 1;
                $fare = $min_rate + ($extraDistance * $fare_per_km);
            }

            // ? Apply promo type discount
            if ($promo->type === 'fixed') {
                $fare = max(0, $fare - $promo->value);
            } elseif ($promo->type === 'percent') {
                $discount = ($fare * $promo->value) / 100;
                if (!empty($promo->max_discount) && $discount > $promo->max_discount) {
                    $discount = $promo->max_discount;
                }
                $fare = max(0, $fare - $discount);
            }

            $vehicle->calculated_fare = round($fare);
            return $vehicle;
        });

    // ? Update ride with promo code (optional � keeps last fare updated)
    DB::table('rides')->where('id', $rideId)->update([
        'promo_code' => $promo->code,
        'updated_at' => now(),
    ]);

    // ? Response
    return apiResponse([
        'ride_id' => $rideId,
        'promo_applied' => true,
        'promo_message' => 'Promo code applied successfully!',
        'distance' => round($distance, 2),
        'vehicles' => $vehicles,
    ], 'Promo code applied and fares updated for all vehicles.');
}
}



