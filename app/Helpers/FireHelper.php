<?php

use App\Models\User;
use App\Notifications\FirebasePushNotification;
use Illuminate\Support\Facades\Log;

if (!function_exists('is_valid_device_token')) {
    function is_valid_device_token(?string $deviceToken): bool
    {
        return !empty($deviceToken) && $deviceToken !== 'default_token';
    }
}

if (!function_exists('app_foreground_idle_seconds')) {
    function app_foreground_idle_seconds(): int
    {
        return max(10, (int) env('APP_FOREGROUND_IDLE_SECONDS', 30));
    }
}

if (!function_exists('is_user_actively_in_app')) {
    function is_user_actively_in_app($user): bool
    {
        if (!$user) {
            return false;
        }

        if (!(bool) ($user->is_app_foreground ?? false)) {
            return false;
        }

        if (!$user->last_seen_at) {
            return true;
        }

        return $user->last_seen_at->gte(now()->subSeconds(app_foreground_idle_seconds()));
    }
}

if (!function_exists('should_send_push_notification')) {
    function should_send_push_notification($user): bool
    {
        if (!$user) {
            return true;
        }

        if (!$user->id) {
            return !is_user_actively_in_app($user);
        }

        $freshUser = User::find($user->id);

        if (!$freshUser) {
            return true;
        }

        return !is_user_actively_in_app($freshUser);
    }
}

if (!function_exists('can_driver_receive_ride_notifications')) {
    function can_driver_receive_ride_notifications($user): bool
    {
        if (!$user) {
            return false;
        }

        // The app's "foreground" flag is unreliable (a backgrounded app keeps polling the API and
        // keeps its socket open), so a ride request push is never skipped because of it.
        return $user->role === 'driver'
            && (int) $user->last_login_at === 1
            && is_valid_device_token($user->device_token);
    }
}

if (!function_exists('filter_active_driver_ids')) {
    function filter_active_driver_ids(array $driverIds): array
    {
        if (empty($driverIds)) {
            return [];
        }

        return User::query()
            ->whereIn('id', $driverIds)
            ->where('role', 'driver')
            ->where('last_login_at', 1)
            ->whereNotNull('device_token')
            ->where('device_token', '!=', 'default_token')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

if (!function_exists('get_online_driver_ids_for_socket')) {
    /**
     * Drivers eligible for realtime socket events (driver mode on; token not required).
     */
    function get_online_driver_ids_for_socket(array $driverIds): array
    {
        if (empty($driverIds)) {
            return [];
        }

        return User::query()
            ->whereIn('id', $driverIds)
            ->where('role', 'driver')
            ->where('last_login_at', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

if (!function_exists('active_driver_mode_query')) {
    /**
     * Drivers currently in driver mode (logged in as driver).
     * Does not require device token — used for socket/list visibility.
     */
    function active_driver_mode_query()
    {
        return User::query()
            ->where('role', 'driver')
            ->where('last_login_at', 1);
    }
}

if (!function_exists('nearby_active_driver_query')) {
    function nearby_active_driver_query()
    {
        return active_driver_mode_query()
            ->whereNotNull('device_token')
            ->where('device_token', '!=', 'default_token');
    }
}

if (!function_exists('send_firebase_notification')) {
    function send_firebase_notification($title, $body, $deviceToken, $user = null, array $data = [], bool $force = false)
    {
        if (!is_valid_device_token($deviceToken)) {
            return false;
        }

        if (!$force) {
            if (!$user) {
                $user = User::where('device_token', $deviceToken)->first();
            }

            if ($user && !should_send_push_notification($user)) {
                return false;
            }
        }

        try {
            $notification = new FirebasePushNotification($title, $body, $deviceToken, $data);
            $result = $notification->toFirebase();

            // A token FCM says is gone for good (app uninstalled / token rotated) can never receive
            // a push: forget it so we stop wasting a request on it for every future ride.
            if (is_array($result) && ($result['fcm_error'] ?? null) === 'UNREGISTERED') {
                User::where('device_token', $deviceToken)->update(['device_token' => 'default_token']);
            }

            if (is_array($result) && isset($result['error'])) {
                Log::warning('Firebase push failed', [
                    'user_id' => $user->id ?? null,
                    'error' => $result['error'],
                    'details' => mb_substr((string) ($result['details'] ?? ''), 0, 300),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Firebase Notification Error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('send_user_push_notification')) {
    function send_user_push_notification($user, $title, $body)
    {
        if (!$user || !is_valid_device_token($user->device_token)) {
            return false;
        }

        if (!should_send_push_notification($user)) {
            return false;
        }

        return send_firebase_notification($title, $body, $user->device_token, $user);
    }
}

if (!function_exists('send_driver_fare_update_notification')) {
    /**
     * Fare updates must reach drivers even when the app is open in foreground.
     */
    function send_driver_fare_update_notification($driver, $title, $body, array $data = [])
    {
        if (!$driver || $driver->role !== 'driver' || (int) $driver->last_login_at !== 1) {
            return false;
        }

        if (!is_valid_device_token($driver->device_token)) {
            return false;
        }

        // Always send — foreground push triggers app refresh when socket UI ignores re-show
        try {
            $notification = new FirebasePushNotification($title, $body, $driver->device_token, $data);
            return $notification->toFirebase();
        } catch (\Exception $e) {
            Log::error('Firebase Fare Update Notification Error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('send_driver_ride_notification')) {
    function send_driver_ride_notification($driver, $title, $body, array $data = [])
    {
        if (!can_driver_receive_ride_notifications($driver)) {
            return false;
        }

        return send_firebase_notification($title, $body, $driver->device_token, $driver, $data, true);
    }
}
