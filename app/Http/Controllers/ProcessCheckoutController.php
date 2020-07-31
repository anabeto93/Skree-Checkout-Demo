<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutFormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

class ProcessCheckoutController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param Request $request
     * @return Response|JsonResponse
     */
    public function __invoke(CheckoutFormRequest $request)
    {
        $amount = floatToMinor((float) $request->input('amount'));
        $currency = $request->input('currency');

        $auth = $this->getAuthentication();

        $payload = [
            'transaction_id' => str_replace(" ", "",uniqid().now()->toDateTimeString()),
            'description' => "Just Testing Skree",
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => route('success.page'),
            'items' => [
                [
                    'name' => "Test",
                    'price' => $amount,
                    'quantity' => 1,
                ]
            ],
        ];

        $url = config('checkout.initiate');

        $headers = [
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'authorization' => $auth,
        ];

        $res = sendHttpRequest($url, $payload, $headers, 'json', 'POST');

        Log::debug("Checkout Response", ['response' => $res]);

        $response = ['error_code' => 500, 'message' => "Error Processing Request."];

        if(is_array($res) && array_key_exists('error_code', $res) && array_key_exists('data', $res)) {
            if ($res['error_code'] == 201) {
                $response = [
                    'message' => "Successfully initated.",
                    'error_code' => 201,
                    'url' => $res['data']['redirect_url'],
                ];
            }
        }

        return response()->json($response);
    }

    private function getAuthentication(): string
    {
        try {
            $auth = Cache::store('redis')->get('checkout_auth');

            if ($auth) return "Bearer ".$auth;

            $url = config('checkout.auth_url');
            $headers = [
                'content-type' => 'application/json',
                'accept' => 'application/json',
                'authorization' => "Basic ".config('checkout.auth'),
            ];

            $payload = [];

            $res = sendHttpRequest($url, $payload, $headers, 'json', 'POST');

            Log::debug("Auth Response", ['auth' => $res]);

            if (is_array($res)) {
                //check for error_code 200 and data
                if (array_key_exists('error_code', $res) && array_key_exists('data', $res)) {
                    if ($res['error_code'] == 200) {
                        $auth = $res['data']['access_token'];

                        //now store it in cache for subsequent requests
                        Cache::store('redis')->put('checkout_auth', $auth, now()->addMinutes(59));

                        $auth = "Bearer ".$auth;
                    }
                }
            }
        } catch(\Exception|InvalidArgumentException $e) {
            Log::error("Error Cache", ['error' => $e->getMessage()]);
            return "Bearer error";
        }

        return $auth;
    }
}
