<?php

return [
    // true: OTP-verified users log in with their number only (no SMS). false: OTP on every login.
    'number_only_login' => (bool) env('NUMBER_ONLY_LOGIN', false),

    // Number-only login needs a push token (sent by the app, or already stored). Without one the
    // user is sent through OTP so the token is captured. Set false to always allow number-only login.
    'require_push_token' => (bool) env('LOGIN_REQUIRE_PUSH_TOKEN', true),
];
