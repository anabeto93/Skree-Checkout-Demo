<?php

return [
    'initiate' => env('CHECKOUT_URL', "https://localhost:453/api/v1/initiate"),
    'auth' => env('CHECKOUT_AUTHORIZATION'),
    'auth_url' => env('CHECKOUT_AUTH_URL', "https://localhost:453/api/v1/authenticate"),
];
