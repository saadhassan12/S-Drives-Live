<?php

use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

if (!function_exists('driver_ride_radius_km')) {
    function driver_ride_radius_km(): float
    {
        return max(1, (float) config('ride.driver_radius_km', 10));
    }
}

if (!function_exists('calculate_geo_distance_km')) {
    function calculate_geo_distance_km(float $startLat, float $startLng, float $endLat, float $endLng): float
    {
        $earthRadius = 6371;
        $latDistance = deg2rad($endLat - $startLat);
        $lonDistance = deg2rad($endLng - $startLng);
        $a = sin($latDistance / 2) * sin($latDistance / 2)
            + cos(deg2rad($startLat)) * cos(deg2rad($endLat))
            * sin($lonDistance / 2) * sin($lonDistance / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}

if (!function_exists('get_geo_bounds')) {
    function get_geo_bounds(float $latitude, float $longitude, float $distanceKm): array
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
}

if (!function_exists('compatible_vehicle_category_ids')) {
    function compatible_vehicle_category_ids(int $vehicleCategoryId): array
    {
        $compatibleCategories = [
            1 => [1, 2],
            2 => [1, 2],
            4 => [4, 5],
            5 => [5],
        ];

        return $compatibleCategories[$vehicleCategoryId] ?? [$vehicleCategoryId];
    }
}

if (!function_exists('allocate_socket_reshow_ride_id')) {
    /**
     * App dismisses a ride after 60s by id. Fare update must look like a new ride
     * on driver:nearby-rides:list. Bid/accept still resolve back to the real ride.
     */
    function allocate_socket_reshow_ride_id(int $originalId): int
    {
        // The database cache driver returns false when incrementing a missing key,
        // which made every fare update share id 1800000000 (and alias to the wrong ride).
        Cache::add('socket_reshow_seq', 0);
        $seq = Cache::increment('socket_reshow_seq');
        if (!is_int($seq) || $seq <= 0) {
            $seq = random_int(1, 300000000);
        }

        $displayId = 1800000000 + $seq;
        Cache::put("socket_ride_alias_{$displayId}", $originalId, now()->addHours(6));

        return $displayId;
    }
}

if (!function_exists('resolve_ride_id')) {
    function resolve_ride_id($rideId): int
    {
        $rideId = (int) $rideId;
        if ($rideId <= 0) {
            return $rideId;
        }

        $original = Cache::get("socket_ride_alias_{$rideId}");

        return $original ? (int) $original : $rideId;
    }
}

if (!function_exists('remember_ride_notified_drivers')) {
    function remember_ride_notified_drivers(int $rideId, array $driverIds): void
    {
        if (empty($driverIds)) {
            return;
        }

        $key = "ride_{$rideId}_notified_drivers";
        $existing = Cache::get($key, []);
        Cache::put(
            $key,
            array_values(array_unique(array_merge($existing, array_map('intval', $driverIds)))),
            now()->addHours(2)
        );
    }
}

if (!function_exists('get_ride_previously_notified_driver_ids')) {
    function get_ride_previously_notified_driver_ids(int $rideId): array
    {
        return array_map('intval', Cache::get("ride_{$rideId}_notified_drivers", []));
    }
}

if (!function_exists('cache_pending_fare_ride_for_driver')) {
    function cache_pending_fare_ride_for_driver(int $driverId, int $rideId, array $rideDetails, int $seconds = 60, ?int $displayId = null): void
    {
        Cache::put("driver_{$driverId}_pending_fare_ride", [
            'ride_id' => $rideId,
            'display_id' => $displayId,
            'ride_details' => $rideDetails,
            'final_fare' => $rideDetails['final_fare'] ?? $rideDetails['estimated_fare'] ?? null,
            'estimated_fare' => $rideDetails['estimated_fare'] ?? null,
            'fare_updated' => true,
            'updated_at' => now()->toIso8601String(),
        ], now()->addSeconds(max($seconds, 60)));
    }
}

if (!function_exists('get_pending_fare_rides_for_driver')) {
    function get_pending_fare_rides_for_driver(int $driverId): array
    {
        $pending = Cache::get("driver_{$driverId}_pending_fare_ride");

        if (!is_array($pending) || empty($pending['ride_details'])) {
            return [];
        }

        $rideId = (int) ($pending['ride_id'] ?? ($pending['ride_details']['id'] ?? 0));
        if ($rideId > 0) {
            $liveRide = Ride::find($rideId);
            if (
                ! $liveRide
                || ! in_array($liveRide->status, ['requested', 'in_progress'], true)
                || (int) $liveRide->time_out === 1
            ) {
                Cache::forget("driver_{$driverId}_pending_fare_ride");

                return [];
            }

            $pending['final_fare'] = $liveRide->final_fare ?? $liveRide->estimated_fare;
            $pending['estimated_fare'] = $liveRide->estimated_fare ?? $liveRide->final_fare;
            $pending['ride_details']['final_fare'] = $pending['final_fare'];
            $pending['ride_details']['estimated_fare'] = $pending['estimated_fare'];
            $pending['ride_details']['status'] = $liveRide->status;
        }

        $ride = $pending['ride_details'];
        $displayId = (int) ($pending['display_id'] ?? 0);
        if ($displayId > 0) {
            $ride['id'] = $displayId;
            $ride['ride_id'] = $displayId;
        }
        $ride['fare_updated'] = true;
        $ride['final_fare'] = $pending['final_fare'] ?? $ride['final_fare'] ?? $ride['estimated_fare'] ?? null;
        $ride['estimated_fare'] = $pending['estimated_fare'] ?? $ride['estimated_fare'] ?? $ride['final_fare'] ?? null;

        return [$ride];
    }
}

if (!function_exists('clear_pending_fare_ride_for_driver')) {
    function clear_pending_fare_ride_for_driver(int $driverId, ?int $rideId = null): void
    {
        $key = "driver_{$driverId}_pending_fare_ride";
        $pending = Cache::get($key);

        if (!is_array($pending)) {
            return;
        }

        if ($rideId !== null && (int) ($pending['ride_id'] ?? 0) !== $rideId) {
            return;
        }

        Cache::forget($key);
    }
}

if (!function_exists('find_drivers_for_fare_update')) {
    /**
     * Nearby drivers plus any previously notified driver who is still eligible.
     */
    function find_drivers_for_fare_update(Ride $ride, ?float $radiusKm = null): Collection
    {
        $radiusKm = $radiusKm ?? driver_ride_radius_km();
        $categoryIds = compatible_vehicle_category_ids((int) $ride->vehicle_category_id);
        $previousDriverIds = get_ride_previously_notified_driver_ids((int) $ride->id);

        $nearbyDrivers = find_nearby_drivers_for_ride(
            (float) $ride->start_latitude,
            (float) $ride->start_longitude,
            $categoryIds,
            $radiusKm
        );

        if (empty($previousDriverIds)) {
            return $nearbyDrivers;
        }

        $previousDrivers = active_driver_mode_query()
            ->select('id', 'latitude', 'longitude', 'device_token', 'role', 'last_login_at', 'is_online', 'is_app_foreground')
            ->whereIn('id', $previousDriverIds)
            ->get();

        return $nearbyDrivers->merge($previousDrivers)->unique('id')->values();
    }
}

if (!function_exists('notify_drivers_fare_updated')) {
    /**
     * Re-show a ride to eligible drivers after the passenger increases fare.
     */
    function notify_drivers_fare_updated(Ride $ride, $fareAmount): void
    {
        $fareAmount = is_numeric($fareAmount) ? round((float) $fareAmount) : $fareAmount;

        $ride->final_fare = $fareAmount;
        $ride->estimated_fare = $fareAmount;
        $ride->touch();
        $ride->save();

        $seconds = ride_visibility_seconds();
        $radiusKm = driver_ride_radius_km();
        $previousDriverIds = get_ride_previously_notified_driver_ids((int) $ride->id);
        $drivers = find_drivers_for_fare_update($ride, $radiusKm);
        $allDriverIds = $drivers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allDriverIds = array_values(array_unique(array_merge($allDriverIds, $previousDriverIds)));

        remember_ride_notified_drivers((int) $ride->id, $allDriverIds);

        if (!empty($allDriverIds)) {
            mark_ride_visible_for_drivers($allDriverIds, (int) $ride->id, $seconds);
        }

        $rideDetails = Ride::with(['user', 'vehicleCategory'])
            ->find($ride->id)
            ?->toArray();

        if (is_array($rideDetails)) {
            $rideDetails['final_fare'] = $ride->final_fare;
            $rideDetails['estimated_fare'] = $ride->estimated_fare;
            $rideDetails['fare_updated'] = true;
        }

        $displayId = allocate_socket_reshow_ride_id((int) $ride->id);

        foreach ($allDriverIds as $driverId) {
            cache_pending_fare_ride_for_driver((int) $driverId, (int) $ride->id, $rideDetails, $seconds, $displayId);
        }

        $notifyDrivers = User::query()
            ->whereIn('id', $allDriverIds)
            ->where('role', 'driver')
            ->get();

        foreach ($notifyDrivers as $driver) {
            send_driver_fare_update_notification(
                $driver,
                'Fare Updated',
                'Ride fare has been updated to ' . $fareAmount,
                [
                    'type' => 'fare_updated',
                    'action' => 'refresh_nearby_rides',
                    'ride_id' => (string) $ride->id,
                    'final_fare' => (string) $fareAmount,
                ]
            );
        }

        $ridePayload = is_array($rideDetails) ? $rideDetails : [
            'ride_id' => (int) $ride->id,
            'id' => (int) $ride->id,
            'start' => $ride->start,
            'destination' => $ride->destination,
            'start_latitude' => $ride->start_latitude,
            'start_longitude' => $ride->start_longitude,
            'end_latitude' => $ride->end_latitude,
            'end_longitude' => $ride->end_longitude,
            'estimated_fare' => $ride->estimated_fare,
            'final_fare' => $ride->final_fare,
            'vehicle_category_id' => $ride->vehicle_category_id,
            'status' => $ride->status,
        ];

        $ridePayload['ride_id'] = (int) $ride->id;
        $ridePayload['id'] = (int) $ride->id;
        $ridePayload['socket_display_id'] = $displayId;
        $ridePayload['final_fare'] = $ride->final_fare;
        $ridePayload['estimated_fare'] = $ride->estimated_fare;
        $ridePayload['max_radius_km'] = $radiusKm;
        $ridePayload['fare_updated'] = true;

        notify_drivers_new_ride($allDriverIds, $ridePayload, true, $seconds);
    }
}

if (!function_exists('find_nearby_drivers_for_ride')) {
    /**
     * Active drivers within configured radius who can receive ride notifications.
     *
     * @param  list<int>  $vehicleCategoryIds
     */
    function find_nearby_drivers_for_ride(
        float $rideLat,
        float $rideLng,
        array $vehicleCategoryIds,
        ?float $radiusKm = null
    ): Collection {
        $radiusKm = $radiusKm ?? driver_ride_radius_km();
        [$minLat, $maxLat, $minLng, $maxLng] = get_geo_bounds($rideLat, $rideLng, $radiusKm);

        return active_driver_mode_query()
            ->select('id', 'latitude', 'longitude', 'device_token', 'role', 'last_login_at', 'is_online', 'is_app_foreground')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->whereHas('vehicles', function ($query) use ($vehicleCategoryIds) {
                $query->whereIn('vehicle_category_id', $vehicleCategoryIds);
            })
            ->get()
            ->filter(function (User $driver) use ($rideLat, $rideLng, $radiusKm) {
                return calculate_geo_distance_km(
                    $rideLat,
                    $rideLng,
                    (float) $driver->latitude,
                    (float) $driver->longitude
                ) <= $radiusKm;
            })
            ->values();
    }
}
