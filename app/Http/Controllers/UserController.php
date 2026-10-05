<?php

namespace App\Http\Controllers;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\Ride;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;



class UserController extends Controller
{


  

public function getOtp(Request $request)
{
    $request->validate([
        'mobile_number' => 'required',
    ]);

    // ? Normalize number
    $mobile_number = trim($request->input('mobile_number'));
    $mobile_number = preg_replace('/\s+/', '', $mobile_number); // remove spaces

    // If doesn't start with +, convert 0300... ? +92300...
    if (substr($mobile_number, 0, 1) !== '+') {
        $mobile_number = '+92' . substr($mobile_number, 1);
    }

    // ? Define your special test numbers
    $special_numbers = [
        '+923022222222',
        '+923011111111',
        '+923047512743',
	    '+923400423649',
        '+923452025550',
        '+923122222222',
        '+923133333333',
        '+923144444444',
        '+923155555555',
        '+923166666666',
        '+923177777777',
        '+923188888888',
        '+923199999999',    
        '+923200000000',

    ];

    // ? Decide OTP
    if (in_array($mobile_number, $special_numbers)) {
        $otp = '4455'; // fixed for testing
    } else {
        $otp = (string) rand(1000, 9999); // random OTP for others
    }

    // ? Send OTP via VeevoTech API
    $hash = env('VEEVOTECH_HASH');
    $textmessage = "Your OTP Code is " . $otp;

    $url = "https://api.veevotech.com/v3/sendsms?" .
        "hash=" . urlencode($hash) .
        "&receivernum=" . urlencode($mobile_number) .
        "&sendernum=" . urlencode('Default') .
        "&textmessage=" . urlencode($textmessage);

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return response()->json([
                'message' => 'Network error while sending OTP: ' . curl_error($ch)
            ], 500);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200) {
            return response()->json([
                'message' => "Failed to send OTP. HTTP Status: " . $httpCode
            ], 500);
        }

        $decodedResponse = json_decode($response, true);

        if (!$decodedResponse) {
            return response()->json([
                'message' => 'Invalid response from SMS gateway'
            ], 500);
        }

        if (isset($decodedResponse['STATUS']) && $decodedResponse['STATUS'] == 'ERROR') {
            $errorMsg = $decodedResponse['ERROR_DESCRIPTION'] ?? 'Unknown Error';

            if (str_contains($errorMsg, 'Failed to route traffic')) {
                return response()->json([
                    'message' => 'OTP could not be delivered because the recipient network is unavailable. Please try another mobile number or check if the SIM is active.'
                ], 400);
            }

            return response()->json([
                'message' => 'Failed to send OTP. Error: ' . $errorMsg
            ], 400);
        }

        // ? Save OTP in DB only after successful SMS
        Otp::updateOrCreate(
            ['mobile_number' => $mobile_number],
            [
                'otp' => $otp,
                'expire_at' => now()->addHour(),
            ]
        );

        return apiResponse(null, 'OTP has been sent to your mobile number, Please check your inbox');

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Something went wrong while sending OTP. ' . $e->getMessage()
        ], 500);
    }
}


    public function verifyOtp(Request $request)
    {
        // Validate the request data
        $request->validate([
            'mobile_number' => 'required|regex:/^(\+92)[0-9]{10}$/',
            'otp' => 'required|digits:4',
            'device_token' => 'nullable',
        ]);

        // Format the mobile number if necessary
        $mobile_number = $request->input('mobile_number');
        if (substr($mobile_number, 0, 1) !== '+') {
            $mobile_number = '+92' . substr($mobile_number, 1);
        }
        $otp = $request->input('otp');
        $otpRecord = Otp::where('mobile_number', $mobile_number)->where('otp', $otp)->first();

        if ($otpRecord) {
            $user = User::withTrashed()->where('mobile_number', $mobile_number)->first();

            if ($user) {
                // Check if the user is soft-deleted
                if ($user->trashed()) {
                    return apiResponse(null, 'Your account has been deleted. Please contact admin for assistance.', 403);
                }

                // Same number se naya login — purani session auto logout.
                $user->tokens()->where('revoked', false)->update(['revoked' => true]);

                DB::table('login_histories')
                    ->where('user_id', $user->id)
                    ->whereNull('logout_time')
                    ->update(['logout_time' => now()]);

                $token = $user->createToken('Api Token')->accessToken;
                $user->device_token = $request->device_token ?? 'default_token';
                $user->is_online = true;
                $user->save();

                if ((int) $user->last_login_at === 1 && $user->role === 'driver') {
                    DB::table('login_histories')->insert([
                        'user_id' => $user->id,
                        'login_time' => now(),
                    ]);
                }

                return apiResponse(
                    ['user' => $user->fresh(), 'token' => $token],
                    'OTP verified successfully.'
                );
            } else {
                return apiResponse(
                    null,
                    'OTP verified successfully. Please sign in to continue.',
                    200
                );
            }
        } else {
            return apiResponse(null, 'Invalid OTP', 400);
        }
    }
    public function signup(Request $request)
    {

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:1,2',
            'role' => 'nullable|in:1,2',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'mobile_number' => 'required|unique:users,mobile_number|regex:/^(\+92)[0-9]{10}$/',
            'device_token' => 'nullable',
        ]);
        $mobile_number = $request->input('mobile_number');
        if (substr($mobile_number, 0, 1) !== '+') {
            $mobile_number = '+92' . substr($mobile_number, 1);
        }
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('profile_pictures'), $file_name);
            $profile_picture_path = asset('profile_pictures/' . $file_name);
        } else {
            $profile_picture_path = null;
        }

        $user = User::create([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'date_of_birth' => $request->input('date_of_birth'),
            'gender' => $request->input('gender'),
            'profile_picture' => $profile_picture_path,
            'mobile_number' => $mobile_number,
            'otp_verified_at' => now(),
            'device_token' => $request->device_token ?? 'default_token',
           

        ]);

        $token = $user->createToken('Api Token')->accessToken;

        return apiResponse(
            ['user' => $user, 'token' => $token],
            'User successfully signed up.'
        );
    }


    public function profileUpdate(Request $request)
    {
        $user = auth()->user();
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:1,2',
            'role' => 'nullable|in:1,2',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . config('upload.max_image_kb'),
            'mobile_number' => 'required|unique:users,mobile_number,' . $user->id . '|regex:/^(\+92)[0-9]{10}$/',
        ]);
        $mobile_number = $request->input('mobile_number');
        if (substr($mobile_number, 0, 1) !== '+') {
            $mobile_number = '+92' . substr($mobile_number, 1);
        }
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $file_name = time() . '-' . $file->getClientOriginalName();
            $file->move(public_path('profile_pictures'), $file_name);
            $profile_picture_path = asset('profile_pictures/' . $file_name);
            $user->profile_picture = $profile_picture_path;
        } else {
            $profile_picture_path = null;
        }
        $user->update([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'date_of_birth' => $request->input('date_of_birth'),
            'gender' => $request->input('gender'),
            'mobile_number' => $mobile_number,
        ]);

       
    $notificationSent = false;
    if (!empty($user->device_token)) {
        try {
            send_user_push_notification(
                $user,
                'Profile Updated',
                'Your profile has been updated successfully.'
            );
            $notificationSent = true;
        } catch (\Exception $e) {
            \Log::error('Notification Error: ' . $e->getMessage());
        }
    }
        return response()->json([
        'status' => 200,
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => $user,
        'notification_status' => $notificationSent ? 'Notification sent successfully.' : 'Notification not sent.',
    ]);
    }
    public function locationUpdate(Request $request)
{
    $request->validate([
        'latitude' => 'required|numeric',
        'longitude' => 'required|numeric',
    ]);

    $user = auth()->user();

    if ($user->role === 'driver') {
        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');

        // ✅ Check for 0.000000
        if ($lat == 0.0 && $lng == 0.0) {
            return apiResponse(null, 'Invalid location. Update skipped.');
        }

        $user->update([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        return apiResponse($user, 'Location updated successfully');
    }

    return apiResponse(null, 'Permission denied. Only drivers can update their location.');
}

    public function updateAppState(Request $request)
    {
        $request->validate([
            'in_foreground' => 'required|boolean',
        ]);

        $user = auth()->user();
        $user->update([
            'is_app_foreground' => $request->boolean('in_foreground'),
            'last_seen_at' => now(),
        ]);

        return apiResponse($user->fresh(), 'App state updated successfully.');
    }

    public function logout(Request $request)
    {
        $user = auth()->user();

        if ($user) {
            // Revoke all active tokens of the user
            $user->tokens()->where('revoked', false)->update(['revoked' => true]);

            // Cancel active rides before logging out
            Ride::where('user_id', $user->id)
                ->whereIn('status', ['accepted'])
                ->update(['status' => 'canceled']);

            // Stop ride notifications after logout and invalidate push target.
            $user->update([
                'last_login_at' => 0,
                'device_token' => 'default_token',
                'is_online' => false,
                'is_app_foreground' => false,
            ]);
            
            
               DB::table('login_histories')
            ->where('user_id', $user->id)
            ->whereNull('logout_time') // only update the latest session
            ->orderByDesc('id')
            ->limit(1)
            ->update(['logout_time' => now()]);
        }

        return apiResponse(null, 'Logout successful.');
    }
    public function details()
    {
        $userId = auth()->id();
        $user = User::find($userId);

        if (!$user) {
            return apiResponse(null, 'User not found.', 404);
        }

        $freshUser = $user->fresh();
        $freshUser->last_login_at = (int) $freshUser->last_login_at;

        return apiResponse(
            $freshUser,
            'User details retrieved successfully.'
        );
    }
    public function softDeleteUser(Request $request, $id)
    {

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $user->delete();
        return apiResponse(null, 'User has been soft deleted successfully.');
    }





public function logoutUserById(): JsonResponse
{
    // Fetch all users having device_token = 'default_token'
    $users = User::where('device_token', 'default_token')->get();

    if ($users->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No users found with device_token = default_token.'
        ], 404);
    }

    $loggedOutUsers = [];

    foreach ($users as $user) {
        $user->tokens()->where('revoked', false)->update(['revoked' => true]);
        $user->update([
            'last_login_at' => 0,
            'device_token' => 'default_token',
        ]);

        $loggedOutUsers[] = [
            'user_id' => $user->id,
        ];
    }

    return response()->json([
        'success' => true,
        'message' => 'All users with device_token = default_token have been logged out successfully.',
        'details' => $loggedOutUsers
    ]);
}



}
