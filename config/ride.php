<?php

return [
    // Drivers within this radius (km) receive ride notifications and see nearby rides.
    'driver_radius_km' => (float) env('DRIVER_RIDE_RADIUS_KM', 10),

    // How long a ride stays visible to drivers after create, fare update, or bid.
    'visibility_seconds' => (int) env('RIDE_VISIBILITY_SECONDS', 120),
];
