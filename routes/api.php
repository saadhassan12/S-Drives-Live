<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\ModeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DropDownController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\RidesController;
use App\Http\Controllers\SocketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AutoLogout;
use App\Http\Controllers\RideHistoryController;
use App\Http\Middleware\ClearCache;
//user
Route::controller(UserController::class)->middleware([AutoLogout::class])->group(function(){
    Route::post('/get-otp', 'getOtp')->name('get-otp');
    Route::post('/verify-otp', 'verifyOtp')->name('verify-otp');
    Route::post('/signup', 'signup')->name('signup');
});

Route::any('/logout-user', [UserController::class, 'logoutUserById']);

//user
Route::controller(UserController::class)->middleware(['auth:api', AutoLogout::class])->group(function () {
    Route::get('/user',  'details');
    Route::post('/profile-update',  'profileUpdate')->name('profile.update');
    Route::post('/location-update',  'locationUpdate');
    Route::post('/app-state', 'updateAppState')->name('app.state');
    Route::post('/logout', 'logout')->name('logout');
    Route::post('delete-user', 'deleteUser')->name('user.delete');
    Route::get('/booking/cancel',  'cancelBooking')->name('booking.cancel');
    Route::get('/users/{id}', 'softDeleteUser')->name('users.softDelete');

});

Route::controller(SocketController::class)->middleware(['auth:api'])->group(function () {
    Route::get('/socket/me', 'me');
});

Route::controller(SocketController::class)->group(function () {
    Route::post('/socket/internal/presence', 'updatePresence');
    Route::post('/socket/internal/activity', 'touchActivity');
    Route::post('/socket/internal/driver-location', 'driverLocation');
});
// passenger / driver mode
Route::controller(ModeController::class)->middleware(['auth:api', AutoLogout::class])->group(function () {
    Route::get('/passenger-mode', 'passengermood')->name('passenger.mood');
    Route::post('/passenger-mode', 'passengermood');
    Route::get('/driver-mode', 'drivermood')->name('driver.mood');
    Route::post('/driver-mode', 'drivermood');
});
//driver
Route::controller(RideHistoryController::class)->middleware(['auth:api', AutoLogout::class])->group(function () {
    Route::get('/driver/rides/history', 'driver');
    Route::get('/ride/history', 'user');
});

Route::controller(DriverController::class)
    ->middleware(['auth:api', AutoLogout::class, 'clearcache'])
    ->group(function () {
        Route::prefix('driver')->group(function () {
            Route::post('/cnic', 'drivercnic')->name('cnic');
            Route::post('/licenses', 'driverlicenses')->name('licenses');
            Route::post('/vehicles', 'vehicles')->name('vehicles');
            Route::get('/near/by/ride', 'near_ride');
            Route::get('/{id}', 'getCaptainById');
            Route::post('/reach/{rideId}', 'driverreach');
            Route::get('/ride/accept', 'getAcceptedRides');
            Route::get('/all/ridebydriver', 'ridebydriver');
            Route::get('/all/completeride', 'completeride');
            Route::get('/all/totalammountdata', 'totalammountdata');
            Route::post('/startedride/{rideId}', 'startedride');
            Route::post('/pickride/{rideId}', 'pickride');
            Route::post('/{rideId}/complete', 'completeRidesby');
        });
    });
    
//DropDown
Route::controller(DropDownController::class)->middleware(['auth:api', AutoLogout::class])->group(function () {
    Route::get('/get-all-categories',  'getAllCategories');
    Route::get('/getmethod',  'getmethod');
});
//AddressController
Route::controller(AddressController::class)->middleware(['auth:api', AutoLogout::class])->group(function () {
    Route::post('/favorite/addresses',  'favorite_addresses');
    Route::get('/get/favorite/addresses',  'get_favorite_addresses');
    Route::get('/delete/favorite/addresses/{id}',  'delete_favorite_addresses');
});
//rides 
Route::controller(RidesController::class)->middleware(['auth:api', AutoLogout::class, 'clearcache'])->group(function () {

    Route::get('/ride/get/by/user', 'getbyuser');

    Route::prefix('rides')->group(function () {
        Route::post('/create', 'createBooking')->name('rides.create');
        Route::post('/vehicle/update/{id}', 'updateBooking');
        Route::get('/{id}',  'getbyid');
        Route::get('/most/add/address',  'most_address');
        Route::post('/cancel/{id}', 'cancelRide');
        Route::post('/{rideId}/update-amount', 'updateBidAmount');
        Route::get('/active-driver/{rideId}', 'getActiveBids');
        Route::get('/accept/byuser', 'getAcceptedRidesbydriver');
        Route::get('all/ridebyuser', 'getbyuser');
        Route::get('/share/ride/{rideId}', 'generateShareLinks');
	Route::post('/{rideId}/apply-promo',  'applyPromoCode');
    });

	
    //Bid
    Route::post('/ride/{rideId}/bid', [BidController::class, 'placeBid'])->name('place.bid');
    Route::post('/ride/{rideId}/bid/accept/{bid_id}', [BidController::class, 'acceptBid'])->name('accept.bid');
    Route::post('/ride/{rideId}/bid/reject/{bid_id}', [BidController::class, 'rejectBid'])->name('reject.bid');
    Route::get('/get-bid/{rideId}', [BidController::class, 'getbid']);

    //Rating
    Route::post('/ride/{rideId}/rate-driver', [RatingController::class, 'rateDriver'])->name('rate.driver');
    Route::post('/ride/{rideId}/rate-customer', [RatingController::class, 'rateCustomer'])->name('rate.customer');
    
     Route::get('/ride/{rideId}/get-rate-customer', [RatingController::class, 'getrateCustomer']);
    Route::get('/ride/{rideId}/get-rate-driver', [RatingController::class, 'getrateDriver']);
});

Route::controller(ChatController::class)->middleware(['auth:api', AutoLogout::class])->group(function () {
    Route::get('/chat/rooms', 'rooms');
    Route::get('/chat/rides/{ride}/messages', 'messagesByRide');
    Route::get('/chat/rides/{ride}/presence', 'presenceByRide');
    Route::get('/chat/rooms/{room}/messages', 'messages');
    Route::get('/chat/rooms/{room}/presence', 'presence');
    Route::post('/chat/messages', 'sendMessage');
    Route::post('/chat/upload-image', 'uploadImage');
});


    Route::post('/send-notification', function (Request $request) {
    $request->validate([
        'title' => 'required|string',
        'body' => 'required|string',
    ]);

    // Static device token (test token)
    
    $deviceToken = 'dt8ocGXPUE6Co07E6O6Vw1:APA91bEZtkXVX68smpY5mVuMsMnD3Hyq_P0d_nyX8LGQd7DFrtIHnGQ3t8iWTIKAeD3b4tjncTs18HYSx-yvDS4jhhDQEfBhahiIa8yej8yBeLxRtsb5mHU';

    // Use the helper function
    $firebaseResponse = send_firebase_notification(
        $request->title,
        $request->body,
        $deviceToken
        
    );

    return response()->json([
        'message' => 'Notification sent!',
        'firebase_response' => $firebaseResponse,
        // 'sound' => 'notification.wav'
    ]);
})->middleware('auth:api');


