<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\ChatRoom;
use App\Models\Cnic;
use App\Models\DriverLicenses;
use App\Models\Vehicles;
use App\Models\User;
use App\Models\Rating;
use App\Models\Ride;

use Carbon\Carbon;

class DriverController extends Controller
{
    //
    public function drivercnic(request $request)
    {
        $request->validate([
            'cnic_no' => 'required|string|max:255',
            'exp_date' => 'required|string|max:255',
            'front_pic' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'back_pic' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'selfie_with_id' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
        ]);
        if ($request->hasFile('front_pic')) {
            $file = $request->file('front_pic');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('cnic_front'), $file_name);
            $cnic_front = asset('cnic_front/' . $file_name);
        }else{
            $cnic_front = null;
        }
        if ($request->hasFile('back_pic')) {
            $file = $request->file('back_pic');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('back_pic'), $file_name);
            $back_pic = asset('back_pic/' . $file_name);
        }else{
            $back_pic = null;
        }
        if ($request->hasFile('selfie_with_id')) {
            $file = $request->file('selfie_with_id');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('selfie'), $file_name);
            $selfie = asset('selfie/' . $file_name);
        }else{
            $selfie = null;
        }

        $userId = auth()->id();
        $existing = Cnic::where('user_id', $userId)->latest('id')->first();

        $cinc = Cnic::updateOrCreate(
            ['user_id' => $userId],
            [
                'cnic_no' => $request->input('cnic_no'),
                'exp_date' => $request->input('exp_date'),
                'front_pic' => $cnic_front ?? $existing?->front_pic,
                'back_pic' => $back_pic ?? $existing?->back_pic,
                'selfie_with_id' => $selfie ?? $existing?->selfie_with_id,
            ]
        );

        Cnic::where('user_id', $userId)->where('id', '!=', $cinc->id)->delete();
        $cnicData = Cnic::with('user')->find($cinc->id);
        return apiResponse(
            $cnicData,
        );
    }

    public function driverlicenses(Request $request)
    {
        $request->validate([
            'license_no' => 'required|string|max:255',
            'expiration_date' => 'required|string|max:255',
            'licenses_front_pic' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'licenses_back_pic' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'selfie_with_licenses' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
        ]);
        if ($request->hasFile('licenses_front_pic')) {
            $file = $request->file('licenses_front_pic');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('licenses_front_pic'), $file_name);
            $licenses_front_pic = asset('licenses_front_pic/' . $file_name);
        }else{
            $licenses_front_pic = null;
        }
        if ($request->hasFile('licenses_back_pic')) {
            $file = $request->file('licenses_back_pic');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('licenses_back_pic'), $file_name);
            $licenses_back_pic = asset('licenses_back_pic/' . $file_name);
        }else{
            $licenses_back_pic = null;
        }
        if ($request->hasFile('selfie_with_licenses')) {
            $file = $request->file('selfie_with_licenses');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('selfie_with_licenses'), $file_name);
            $selfie = asset('selfie_with_licenses/' . $file_name);
        }else{
            $selfie = null;
        }

        $userId = auth()->id();
        $existing = DriverLicenses::where('user_id', $userId)->latest('id')->first();

        $DriverLicenses = DriverLicenses::updateOrCreate(
            ['user_id' => $userId],
            [
                'license_no' => $request->input('license_no'),
                'expiration_date' => $request->input('expiration_date'),
                'licenses_front_pic' => $licenses_front_pic ?? $existing?->licenses_front_pic,
                'licenses_back_pic' => $licenses_back_pic ?? $existing?->licenses_back_pic,
                'selfie_with_licenses' => $selfie ?? $existing?->selfie_with_licenses,
            ]
        );

        DriverLicenses::where('user_id', $userId)->where('id', '!=', $DriverLicenses->id)->delete();
        $DriverLicensesdata = DriverLicenses::with('user')->find($DriverLicenses->id);
        return apiResponse(
            $DriverLicensesdata,
        );
    }

    public function vehicles(Request $request)
    {
        $request->validate([
            'vehicle_category_id' => 'required|string|max:255',
            'engine' => 'nullable|string|max:255',
            'manufacture_year' => 'nullable|string|max:255',
            'manufacture_model' => 'nullable|string|max:255',
            'manufacture_company' => 'nullable|string|max:255',
            'registration_number' => 'nullable|string|max:255',
            'vehicle_front_pic' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'vehicle_back_pic' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'vehicle_dashboard' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'vehicle_certificate_front' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'vehicle_certificate_back' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'interior' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
        ]);
        if ($request->hasFile('vehicle_front_pic')) {
            $file = $request->file('vehicle_front_pic');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('vehicle_front_pic'), $file_name);
            $vehicle_front_pic = asset('vehicle_front_pic/' . $file_name);
        }
        else{
            $vehicle_front_pic = null;
        }
        if ($request->hasFile('vehicle_back_pic')) {
            $file = $request->file('vehicle_back_pic');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('vehicle_back_pic'), $file_name);
            $vehicle_back_pic = asset('vehicle_back_pic/' . $file_name);
        }else{
            $vehicle_back_pic = null;
        }
        if ($request->hasFile('vehicle_dashboard')) {
            $file = $request->file('vehicle_dashboard');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('vehicle_dashboard'), $file_name);
            $vehicle_dashboard = asset('vehicle_dashboard/' . $file_name);
        }else{
            $vehicle_dashboard = null;
        }
        if ($request->hasFile('vehicle_certificate_front')) {
            $file = $request->file('vehicle_certificate_front');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('vehicle_certificate_front'), $file_name);
            $vehicle_certificate_front = asset('vehicle_certificate_front/' . $file_name);
        }else{
            $vehicle_certificate_front = null;
        }
        if ($request->hasFile('vehicle_certificate_back')) {
            $file = $request->file('vehicle_certificate_back');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('vehicle_certificate_back'), $file_name);
            $vehicle_certificate_back = asset('vehicle_certificate_back/' . $file_name);
        }else{
            $vehicle_certificate_back = null;
        }
        if ($request->hasFile('interior')) {
            $file = $request->file('interior');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('interior'), $file_name);
            $interior = asset('interior/' . $file_name);
        }else{
            $interior = null;
        }

        $userId = auth()->id();
        $existing = Vehicles::where('user_id', $userId)->latest('id')->first();

        $Vehicles = Vehicles::updateOrCreate(
            ['user_id' => $userId],
            [
                'vehicle_category_id' => $request->input('vehicle_category_id'),
                'engine' => $request->input('engine'),
                'manufacture_year' => $request->input('manufacture_year'),
                'manufacture_model' => $request->input('manufacture_model'),
                'manufacture_company' => $request->input('manufacture_company'),
                'courier_servies' => $request->input('courier_servies'),
                'registration_number' => $request->input('registration_number'),
                'vehicle_front_pic' => $vehicle_front_pic ?? $existing?->vehicle_front_pic,
                'vehicle_back_pic' => $vehicle_back_pic ?? $existing?->vehicle_back_pic,
                'vehicle_dashboard' => $vehicle_dashboard ?? $existing?->vehicle_dashboard,
                'vehicle_certificate_front' => $vehicle_certificate_front ?? $existing?->vehicle_certificate_front,
                'vehicle_certificate_back' => $vehicle_certificate_back ?? $existing?->vehicle_certificate_back,
                'interior' => $interior ?? $existing?->interior,
            ]
        );

        Vehicles::where('user_id', $userId)->where('id', '!=', $Vehicles->id)->delete();
        $Vehiclesdata = Vehicles::with('user','vehicle')->find($Vehicles->id);
        return apiResponse(
            $Vehiclesdata,
        );
    }
// public function near_ride()
// {
//     $user = auth()->user();

//     if ($user->role !== 'driver') {
//         return response()->json(['error' => 'Unauthorized'], 403);
//     }

//     $vehicle = Vehicles::where('user_id', $user->id)->first();
//     if (!$vehicle) {
//         return response()->json(['error' => 'Vehicle not found for this driver'], 404);
//     }

//     $driverVehicleCategoryId = $vehicle->vehicle_category_id;
//     $today = Carbon::now();
//     $driverLatitude = $user->latitude;
//     $driverLongitude = $user->longitude;
        
//     $ridesToUpdate = Ride::where('vehicle_category_id', $driverVehicleCategoryId)
//         ->whereRaw("
//             (6371 * acos(
//                 cos(radians(?)) *
//                 cos(radians(start_latitude)) *
//                 cos(radians(start_longitude) - radians(?)) +
//                 sin(radians(?)) *
//                 sin(radians(start_latitude))
//             )) <= 10", [$driverLatitude, $driverLongitude, $driverLatitude])
//         ->where('status', 'in_progress')
//         ->where('time_out', 0)
//         ->whereDate('created_at', $today)
//         ->with(['user'])
//         ->get();

//     if ($ridesToUpdate->isNotEmpty()) {
//         $tenMinutesAgo = Carbon::now()->subMinutes(10);
//         foreach ($ridesToUpdate as $ride) {
//             // ✅ Timeout check
//             if ($ride->created_at <= $tenMinutesAgo) {
//                 $ride->time_out = 1;
//                 $ride->status = 'canceled';
//                 $ride->save();
//                 continue;
//             }
//             $nearbyDrivers = User::where('role', 'driver')
//                 ->whereHas('vehicles', function ($q) use ($driverVehicleCategoryId) {
//                     $q->where('vehicle_category_id', $driverVehicleCategoryId);
//                 })
//                 ->whereRaw("
//                     (6371 * acos(
//                         cos(radians(?)) *
//                         cos(radians(latitude)) *
//                         cos(radians(longitude) - radians(?)) +
//                         sin(radians(?)) *
//                         sin(radians(latitude))
//                     )) <= 10", [$ride->start_latitude, $ride->start_longitude, $ride->start_latitude])
//                 ->get();

//                 foreach ($nearbyDrivers as $driver) {
//                 if (!empty($driver->device_token)) {
//                     send_firebase_notification(
//                         'Nearby Ride Found',
//                         'A passenger nearby is requesting a ride. Accept now before it’s gone.',
//                         $driver->device_token,
//                         ['ride_id' => $ride->id]
//                     );
//                 }
//             }
//         }

//         return apiResponse($ridesToUpdate, 'Nearby rides fetched and drivers notified.');
//     }

//     return apiResponse([], 'Ride not Found', 200, false);
// }
// public function near_ride()
// {
//     $user = auth()->user();
//     if ($user->role !== 'driver') {
//         return response()->json(['error' => 'Unauthorized'], 403);
//     }

//     $vehicle = Vehicles::where('user_id', $user->id)->first();
//     if (!$vehicle) {
//         return response()->json(['error' => 'Vehicle not found for this driver'], 404);
//     }

//     $driverVehicleCategoryId = $vehicle->vehicle_category_id;

//     $compatibleCategories = [
//         1 => [1, 2],
//         2 => [1, 2],
//         4 => [4, 5],    
//         5 => [5],
//     ];

//     $allowedCategories = $compatibleCategories[$driverVehicleCategoryId] ?? [$driverVehicleCategoryId];

//     $today = Carbon::now();
//     $driverLatitude = $user->latitude;
//     $driverLongitude = $user->longitude;
        
//     $ridesToUpdate = Ride::whereIn('vehicle_category_id', $allowedCategories)
//         ->whereRaw("
//             (6371 * acos(
//                 cos(radians(?)) *
//                 cos(radians(start_latitude)) *
//                 cos(radians(start_longitude) - radians(?)) +
//                 sin(radians(?)) *
//                 sin(radians(start_latitude))
//             )) <= 10", [$driverLatitude, $driverLongitude, $driverLatitude])
//         ->where('status', 'in_progress')
//         ->where('time_out', 0)
//         ->whereDate('created_at', $today)
//         ->with(['user','vehicleCategory'])
//         ->get();

// if ($ridesToUpdate->isNotEmpty()) {
//     $tenMinutesAgo = Carbon::now()->subMinutes(10);
//     $firebaseResponse = null;

//     foreach ($ridesToUpdate as $ride) {
//         if ($ride->created_at <= $tenMinutesAgo) {
//             $ride->time_out = 1;
//             $ride->status = 'canceled';
//             $ride->save();

//             if (!empty($user->device_token)) {
//                 $firebaseResponse = send_firebase_notification(
//                     'Ride Canceled',
//                     'Your nearby ride was canceled due to timeout.',
//                     $user->device_token,
//                     ['ride_id' => $ride->id, 'status' => 'canceled']
//                 );
//             }
//             continue;
//         }

//         $cacheKey = 'driver_'.$user->id.'_last_ride';
//         $lastRideId = Cache::get($cacheKey);

//         if ($lastRideId && $lastRideId == $ride->id) {
//             continue;
//         }
//         if (!empty($user->device_token)) {
//             // $firebaseResponse = send_firebase_notification(
//             //     'Nearby Ride Found',
//             //     'A passenger nearby is requesting a ride. Accept now before it’s gone.',
//             //     $user->device_token,
               
//             // );
//             Cache::put($cacheKey, $ride->id, now()->addMinutes(15));
//         }

//         break;
//     }

//     return apiResponse($ridesToUpdate, 'Nearby rides fetched and driver notified.', 200,);
// }

// return apiResponse([], 'Ride not Found', 200, false);



//     return apiResponse([], 'Ride not Found', 200, false);
// }

public function near_ride()
{
    $user = auth()->user();
    if ($user->role !== 'driver') {
        return apiResponse([], 'Unauthorized', 403, false);
    }

    $vehicle = Vehicles::where('user_id', $user->id)->first();
    if (!$vehicle) {
        return apiResponse([], 'Vehicle not found for this driver', 200, false);
    }

    $driverVehicleCategoryId = $vehicle->vehicle_category_id;

    $compatibleCategories = [
        1 => [1, 2],
        2 => [1, 2],
        4 => [4, 5],
        5 => [5],
    ];

    $allowedCategories = $compatibleCategories[$driverVehicleCategoryId] ?? [$driverVehicleCategoryId];

    $today = Carbon::now();
    $driverLatitude = $user->latitude;
    $driverLongitude = $user->longitude;
    $radiusKm = driver_ride_radius_km();
    $hasLocation = $driverLatitude !== null && $driverLongitude !== null;

    $ridesToUpdate = collect();

    if ($hasLocation) {
        [$minLat, $maxLat, $minLng, $maxLng] = $this->getNearbyBounds(
            (float) $driverLatitude,
            (float) $driverLongitude,
            $radiusKm
        );

        $ridesToUpdate = Ride::whereIn('vehicle_category_id', $allowedCategories)
            ->whereBetween('start_latitude', [$minLat, $maxLat])
            ->whereBetween('start_longitude', [$minLng, $maxLng])
            ->whereIn('status', ['requested', 'in_progress'])
            ->where('time_out', 0)
            ->where('created_at', '>=', $today->copy()->startOfDay())
            ->with(['user', 'vehicleCategory'])
            ->get()
            ->map(function ($ride) use ($driverLatitude, $driverLongitude, $radiusKm) {
                $distanceKm = calculate_geo_distance_km(
                    (float) $driverLatitude,
                    (float) $driverLongitude,
                    (float) $ride->start_latitude,
                    (float) $ride->start_longitude
                );

                $ride->driver_distance_km = round($distanceKm, 2);
                $ride->max_radius_km = $radiusKm;

                return $ride;
            })
            ->filter(function ($ride) use ($radiusKm, $user) {
                if ($ride->driver_distance_km > $radiusKm) {
                    return false;
                }

                $visibleByTime = $ride->updated_at
                    && $ride->updated_at->gte(now()->subSeconds(ride_visibility_seconds()));
                $visibleByCache = is_ride_visible_for_driver((int) $user->id, (int) $ride->id);

                return $visibleByTime || $visibleByCache;
            })
            ->values();
    }

    $pendingFare = Cache::get("driver_{$user->id}_pending_fare_ride");
    $pendingOriginalId = (int) ($pendingFare['ride_id'] ?? 0);
    $pendingDisplayId = (int) ($pendingFare['display_id'] ?? 0);

    $ridesToUpdate = $ridesToUpdate->map(function ($ride) use ($pendingOriginalId, $pendingDisplayId) {
        if ($pendingDisplayId > 0 && (int) $ride->id === $pendingOriginalId) {
            $row = $ride->toArray();
            $row['id'] = $pendingDisplayId;
            $row['ride_id'] = $pendingDisplayId;
            $row['fare_updated'] = true;

            return (object) $row;
        }

        return $ride;
    })->values();

    $pendingFareRides = collect(get_pending_fare_rides_for_driver((int) $user->id))
        ->map(function ($ride) use ($driverLatitude, $driverLongitude, $radiusKm, $hasLocation) {
            if (is_array($ride)) {
                $ride = (object) $ride;
            }

            if ($hasLocation) {
                $rideLat = (float) ($ride->start_latitude ?? 0);
                $rideLng = (float) ($ride->start_longitude ?? 0);
                $distanceKm = calculate_geo_distance_km(
                    (float) $driverLatitude,
                    (float) $driverLongitude,
                    $rideLat,
                    $rideLng
                );

                $ride->driver_distance_km = round($distanceKm, 2);
            }

            $ride->max_radius_km = $radiusKm;
            $ride->fare_updated = true;

            return $ride;
        })
        ->filter()
        ->values();

    $existingIds = $ridesToUpdate->pluck('id')->map(fn ($id) => (int) $id)->all();

    foreach ($pendingFareRides as $pendingRide) {
        $pendingId = (int) ($pendingRide->id ?? 0);
        if ($pendingId > 0 && !in_array($pendingId, $existingIds, true)) {
            $ridesToUpdate->push($pendingRide);
            $existingIds[] = $pendingId;
        }
    }

    if ($ridesToUpdate->isNotEmpty()) {
        foreach ($ridesToUpdate as $visibleRide) {
            remember_ride_notified_drivers(resolve_ride_id((int) $visibleRide->id), [(int) $user->id]);
        }

        return apiResponse($ridesToUpdate->values(), 'Nearby rides fetched successfully.', 200);
    }

    return apiResponse([], 'Ride not found', 200, false);
}

    protected function calculateDistance($startLat, $startLong, $endLat, $endLong)
    {
        $earthRadius = 6371;
        $latDistance = deg2rad($endLat - $startLat);
        $lonDistance = deg2rad($endLong - $startLong);
        $a = sin($latDistance / 2) * sin($latDistance / 2) +
            cos(deg2rad($startLat)) * cos(deg2rad($endLat)) *
            sin($lonDistance / 2) * sin($lonDistance / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
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

    public function getCaptainById($id)
    {
        $captain = User::with('driverCnic', 'driverliceses', 'vehicles')
            ->where('role', 'driver')
            ->find($id);

        if (!$captain) {
            return apiResponse(null, 'Driver not found', 404, false);
        }

        // One pass over this driver's rides (uses the driver_id index).
        $rideStats = Ride::where('driver_id', $captain->id)
            ->whereIn('status', ['completed', 'canceled'])
            ->selectRaw("COALESCE(SUM(status = 'completed'), 0) as completed_count")
            ->selectRaw("COALESCE(SUM(status = 'canceled'), 0) as canceled_count")
            ->selectRaw("MIN(CASE WHEN status = 'completed' THEN created_at END) as first_ride_at")
            ->selectRaw("MAX(CASE WHEN status = 'completed' THEN updated_at END) as last_ride_at")
            ->first();

        $completedCount = (int) $rideStats->completed_count;
        $canceledCount = (int) $rideStats->canceled_count;
        $totalFinished = $completedCount + $canceledCount;

        $ratingStats = Rating::where('rated_to', $captain->id)
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->selectRaw('COALESCE(SUM(rating = 5), 0) as r5, COALESCE(SUM(rating = 4), 0) as r4')
            ->selectRaw('COALESCE(SUM(rating = 3), 0) as r3, COALESCE(SUM(rating = 2), 0) as r2')
            ->selectRaw('COALESCE(SUM(rating = 1), 0) as r1')
            ->first();

        $ratingCount = (int) $ratingStats->total;
        $averageRating = $ratingCount > 0 ? round((float) $ratingStats->average, 2) : 0;

        $canceledByDriver = DB::table('cancel_ride')
            ->where('user_id', $captain->id)
            ->where('canceled_by', 'driver')
            ->count();

        $recentReviews = Rating::query()
            ->where('ratings.rated_to', $captain->id)
            ->leftJoin('users', 'users.id', '=', 'ratings.rated_by')
            ->orderByDesc('ratings.id')
            ->limit(5)
            ->get([
                'ratings.id', 'ratings.ride_id', 'ratings.rating', 'ratings.comment', 'ratings.created_at',
                'users.first_name as reviewer_first_name', 'users.last_name as reviewer_last_name',
                'users.profile_picture as reviewer_profile_picture',
            ]);

        $vehicle = $captain->vehicles instanceof \Illuminate\Support\Collection
            ? $captain->vehicles->first()
            : $captain->vehicles;

        $memberSince = $captain->created_at;
        $experienceDays = $memberSince ? (int) $memberSince->diffInDays(now()) : 0;

        return apiResponse([
            // Original keys (the app already reads these).
            'captian' => $captain,
            'average_rating' => $averageRating,
            'completed_rides_count' => $completedCount,

            // Complete profile for the driver profile screen.
            'profile' => [
                'id' => $captain->id,
                'first_name' => $captain->first_name,
                'last_name' => $captain->last_name,
                'full_name' => trim($captain->first_name . ' ' . $captain->last_name),
                'profile_picture' => $captain->profile_picture,
                'gender' => $captain->gender,
                'is_online' => (bool) $captain->is_online,
                'driver_status' => $captain->driver_status,
                'member_since' => $memberSince?->toIso8601String(),
                'vehicle' => $vehicle ? [
                    'vehicle_category_id' => $vehicle->vehicle_category_id,
                    'manufacture_company' => $vehicle->manufacture_company,
                    'manufacture_model' => $vehicle->manufacture_model,
                    'manufacture_year' => $vehicle->manufacture_year,
                    'registration_number' => $vehicle->registration_number,
                    'ac' => $vehicle->ac,
                    'vehicle_front_pic' => $vehicle->vehicle_front_pic,
                ] : null,
            ],
            'stats' => [
                'average_rating' => $averageRating,
                'total_ratings' => $ratingCount,
                'rating_breakdown' => [
                    '5' => (int) $ratingStats->r5,
                    '4' => (int) $ratingStats->r4,
                    '3' => (int) $ratingStats->r3,
                    '2' => (int) $ratingStats->r2,
                    '1' => (int) $ratingStats->r1,
                ],
                'total_rides' => $totalFinished,
                'completed_rides' => $completedCount,
                'cancelled_rides' => $canceledCount,
                'cancelled_by_driver' => $canceledByDriver,
                'completion_rate' => $totalFinished > 0 ? round($completedCount / $totalFinished * 100, 1) : 0,
                'experience_days' => $experienceDays,
                'experience_years' => round($experienceDays / 365, 1),
                'first_ride_at' => $rideStats->first_ride_at,
                'last_ride_at' => $rideStats->last_ride_at,
            ],
            'recent_reviews' => $recentReviews,
        ], 'Driver Get By ID');
    }


    /** Driver actions only apply to an open ride that belongs to this driver (never revive a canceled one). */
    private function guardDriverRide(Ride $ride)
    {
        if (!in_array($ride->status, ['accepted', 'driver_reach', 'started_ride', 'ride_pick'], true)) {
            return apiResponse(
                ['ride_id' => $ride->id, 'status' => ride_event_status($ride->status)],
                'This ride is ' . ride_event_status($ride->status) . ' and cannot be updated.',
                409,
                false
            );
        }

        if ($ride->driver_id && (int) $ride->driver_id !== (int) auth()->id()) {
            return apiResponse(null, 'This ride is assigned to another driver.', 403, false);
        }

        return null;
    }

     public function driverreach(Request $request, $rideId)
    {
        $ride = Ride::findOrFail(resolve_ride_id($rideId));
        if ($blocked = $this->guardDriverRide($ride)) {
            return $blocked;
        }
        $previousStatus = $ride->status;
        $ride->driver_id = auth()->id();
        $ride->status = 'driver_reach';
        $ride->save();
        emit_ride_status_changed($ride, $previousStatus);
        
        // ✅ Get the passenger user model (user_id refers to passenger)
        $passenger = \App\Models\User::find($ride->user_id);
        if (!empty($passenger) && !empty($passenger->device_token)) {
            $firebaseResponse = send_user_push_notification(
                $passenger,
                'Driver Arrived',
                'Captain has reached your pickup location.'
            );
        }
        return apiResponse($ride, 'Driver Reach in Your Loction!', 200, );
    }
    
       public function startedride(Request $request, $rideId)
    {
        $ride = Ride::findOrFail(resolve_ride_id($rideId));
        if ($blocked = $this->guardDriverRide($ride)) {
            return $blocked;
        }
        $previousStatus = $ride->status;
        $ride->driver_id = auth()->id();
        $ride->status = 'started_ride';
        $ride->save();
        emit_ride_status_changed($ride, $previousStatus);
    
        // ✅ Get the passenger user model
        $passenger = \App\Models\User::find($ride->user_id);
    
        $firebaseResponse = null;
        if (!empty($passenger) && !empty($passenger->device_token)) {
            try {
                $firebaseResponse = send_user_push_notification(
                    $passenger,
                    'Ride Started',
                    'Your Captain is on the way to pick you up.'
                );
            } catch (\Exception $e) {
                \Log::error('Firebase Notification Error: ' . $e->getMessage());
            }
        }
    
            return apiResponse($ride, 'Driver has started the Ride.', 200,);   
        }

          public function pickride(Request $request, $rideId)
        {
            $ride = Ride::findOrFail(resolve_ride_id($rideId));
        if ($blocked = $this->guardDriverRide($ride)) {
            return $blocked;
        }
            $previousStatus = $ride->status;
            $ride->driver_id = auth()->id();
            $ride->status = 'ride_pick';
            $ride->save();
            emit_ride_status_changed($ride, $previousStatus);
            
                    // ✅ Get the passenger user model
         $passenger = \App\Models\User::find($ride->user_id);
        $firebaseResponse = null;
        if (!empty($passenger) && !empty($passenger->device_token)) {
            try {
                $firebaseResponse = send_user_push_notification(
                    $passenger,
                    'Ride Pick',
                    'Ride Started Have a safe journey with SheDrives.'
                );
            } catch (\Exception $e) {
                \Log::error('Firebase Notification Error: ' . $e->getMessage());
            }
        }
            return apiResponse($ride, 'Driver has Pick the Passenger.',200, );
        }
        


   public function getAcceptedRides(Request $request)
    {

        $rides = Ride::whereIn('status', ['started_ride', 'accepted','driver_reach','ride_pick'])
            ->where('driver_id', auth()->id())
            ->with(['driver', 'user_pe', 'chatRoom'])
            ->get();
         
        $completedCount = Ride::where('status', 'completed')
            ->where('driver_id', auth()->id())
            ->count();
        $averageRating = Rating::where('rated_to', auth()->id())->avg('rating');

        return apiResponse(['ride'=>$rides, 'average_rating' => round($averageRating, 2),
        'completed_rides_count' => $completedCount], 'Accepted rides with assigned drivers retrieved successfully.');
    }
    public function ridebydriver(Request $request)
    {
        $rides = Ride::whereIn('status', ['completed', 'canceled'])
            ->where('driver_id', auth()->id())
            ->with(['driver', 'user_pe', 'vehicles', 'ratings'])
            ->orderByDesc('updated_at')
            ->get();

        return apiResponse($rides, 'Ride history retrieved successfully.');
    }
    public function completeride(Request $request)
    {
        $driverId = auth()->id();

        // Fetch all rides with status 'completed' or 'canceled'
        $rides = Ride::whereIn('status', ['completed'])
            ->where('driver_id', $driverId)
            ->with(['driver', 'user_pe', 'vehicles','ratings'])
            ->get();

        // Count completed rides
        $completedCount = Ride::where('status', 'completed')
            ->where('driver_id', $driverId)
            ->count();

        return apiResponse([
            'rides' => $rides,
            'completed_rides_count' => $completedCount
        ], 'All Complete Rides.');
    }
    
     public function totalammountdata(Request $request)
    {
        $driverId = auth()->id();
        $rides = Ride::where('status', 'completed')
            ->where('driver_id', $driverId)
            ->get(['id', 'final_fare', 'created_at', 'status']);

        // Sum of final_fare
        $totalFinalFare = $rides->sum('final_fare');

        return apiResponse([
            'rides' => $rides,
            'total_final_fare' => $totalFinalFare
        ], 'All Complete Rides.');
    }
    
 public function completeRidesby($rideId)
{
    $ride = Ride::findOrFail(resolve_ride_id($rideId));

    // Sirf driver ya passenger hi ride complete kar sakta hai
    if ($ride->driver_id != auth()->id() && $ride->user_id != auth()->id()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    if (!in_array($ride->status, ['accepted', 'driver_reach', 'started_ride', 'ride_pick'], true)) {
        return apiResponse(
            ['ride_id' => $ride->id, 'status' => ride_event_status($ride->status)],
            'This ride is ' . ride_event_status($ride->status) . ' and cannot be completed.',
            409,
            false
        );
    }

    $previousStatus = $ride->status;
    $ride->status = 'completed';
    $ride->save();
    emit_ride_completed($ride, $previousStatus);

    ChatRoom::where('ride_id', $ride->id)->update([
        'status' => 'closed',
        'ended_at' => now(),
    ]);

    $firebaseResponse = null;

    // ✅ Sirf Passenger ko notification bhejna hai (ride->user_id)
    $passenger = \App\Models\User::find($ride->user_id);
    if (!empty($passenger) && !empty($passenger->device_token)) {
        try {
            $firebaseResponse = send_user_push_notification(
                $passenger,
                'Ride Completed',
                'Your ride has been completed. Don’t forget to rate your captain!'
            );
        } catch (\Exception $e) {
            \Log::error('Firebase Notification Error (Passenger): ' . $e->getMessage());
        }
    }

    return apiResponse($ride->status, 'Ride completed successfully.', 200, $firebaseResponse);
}


}
