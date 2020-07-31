<?php

use GuzzleHttp\Exception\ClientException;


/**
 * Send an HTTP request
 *
 * @param string $url
 * @param array|string $body
 * @param array $headers
 * @param string $type
 * @param string $method
 * @param boolean $verify_ssl
 *
 * @return mixed
 */
function sendHttpRequest($url, $body=[], array $headers=[], $type='json',$method='POST', $verify_ssl=false)
{
    $client = new \GuzzleHttp\Client();

    $resp = 'default';

    try{
        $head = [
            'verify' => $verify_ssl
        ];

        if($type==='json') {
            $head['json'] = $body;
        }else {
            $head[$type] = $body;
        }

        if(!empty($headers) && count($headers) > 0) {
            $auth = [];
            if(array_key_exists('auth',$headers)) {
                $auth = $headers['auth'];
            }
            if(is_array($auth) && count($auth)>0) {
                $head['auth'] = $auth;
            }
            $head['headers'] = $headers;
        }

        if(strtoupper($method) === 'GET') {
            $method = 'GET';
            unset($head[$type]);

            if($body !== null && $body !=='') {
                $head['query'] = $body;
            }else {
                $head['query'] = [];
            }
        }

        $method = strtoupper($method);
        Illuminate\Support\Facades\Log::info('Making an HttpRequest');
        Illuminate\Support\Facades\Log::debug("Payload",['method' => $method, 'url' => $url, 'body' => $head]);

        $resp = $client->request($method,$url,$head);
    } catch (ClientException | \GuzzleHttp\Exception\GuzzleException $e) {
        $init = explode('resulted in a',$e->getMessage());
        Illuminate\Support\Facades\Log::debug('ExceptionMTN', ['mtn' => count($init) && array_key_exists(1,$init) > 0 ? $init[1] : $init[0]]);

        $init = count($init) && array_key_exists(1,$init) > 0 ? $init[1] : $init[0];
        $msg = explode('response:',$init)[0];
        //remove the back ticks
        $b_ticks = explode('`',$msg);
        $msg = count($b_ticks)>0 && array_key_exists(1,$b_ticks) ? $b_ticks[1] : $b_ticks[0];

        $result = [
            'status' => 'Internal Error.',
            'code' => '500',
            'reason' => $msg,
        ];

        //also check for the special messages or string returned as a reason for the failure
        $init = explode('resulted in a',$e->getMessage());
        if(is_array($init) && count($init)>1) {
            if(array_key_exists(1,$init)) {
                $temp = explode('response:',$init[1]);
                if(count($temp)>1) {
                    $temp = array_key_exists(1,$temp) ? $temp[1] : $temp[0];
                    if(isJson($temp)) {
                        $temp = json_decode($temp,true);
                        if(is_array($temp)) {
                            $result['data'] = $temp;
                        }
                    }
                }
            }
        }

        if ($e instanceof ClientException) {
            $new_result = json_decode($e->getResponse()->getBody()->getContents(), true);

            if(!array_key_exists('data', $result)) {
                $result['data'] = $new_result;
            }
        }

        $resp = $resp === 'default' ? 'error' : $resp;// so it skips processing
    }

    if($resp==='default') {
        $result = 'Error occurred';
    }elseif ($resp!=='error'){
        $result = $resp->getBody()->getContents();
        if(is_string($result) && is_null(json_decode($result,true))) {
            if(array_key_exists('headers',$head) && is_array($head['headers']) && array_key_exists('content-type', $head['headers'])) {
                //check if it as application/xml or text/xml
                if(in_array($head['headers']['content-type'],['application/xml','text/xml'])) {
                    $p = xml_parser_create();
                    xml_parse_into_struct($p, $result, $xml_body, $xml_keys);
                    xml_parser_free($p);
                    return $xml_body;
                }
            }

            //Check that is url_encoded and then handle it accordingly
            parse_str(parse_url($result,PHP_URL_QUERY),$arr);
            parse_str($result,$brr);

            if(is_array($arr) && count($arr)>0) {
                return $arr;
            }else if(is_array($brr) && count($brr)>0) {
                return $brr;
            }

            if ((int) $resp->getStatusCode() == 202) {
                $result = successResponse([], 'Success', 202, 'Processing request.');
            } elseif ((200 <= $resp->getStatusCode()) && ($resp->getStatusCode() <= 299)) {
                $result = successResponse([], 'Success', 204, 'Completed with no content.');
            } else {
                $result = errorResponse([],'Error occurred while processing. Please try again later!',500);
            }
        }else{
            $result = json_decode($result,true);
        }
    }

    return $result;
}

/**
 * Default Success Response
 * @param array $data
 * @param string $status
 * @param int|string $code
 * @param string $reason
 * @return array
 */
function successResponse($data=[],$status='Success',$code='000', $reason='')
{
    $status = is_null($status) ? 'Success' : $status;
    $data = is_null($data) ? [] : $data;
    $code = is_null($code) ? '000' : $code;

    $result =['status'=>$status, 'code'=>$code];
    if(is_string($reason) && $reason !=='') {
        $result['reason'] = $reason;
    }
    if(is_array($data) && !empty($data)) {
        $result['data'] = $data;
    }
    return $result;
}

/**
 * Default Error Response
 * @param array $data
 * @param string $status
 * @param int|string $code
 * @param string $reason
 * @return array
 */
function errorResponse($data=[],$status='Error', $code='900', $reason='')
{
    $status = is_null($status) ? 'Error' : $status;
    $data = is_null($data) ? [] : $data;
    $code = is_null($code) ? '900' : $code;

    $result =['status'=>$status, 'code'=>$code];
    if(is_string($reason) && $reason !=='') {
        $result['reason'] = $reason;
    }
    if(is_array($data) && !empty($data)) {
        $result['data'] = $data;
    }
    return $result;
}

/**
 * Fastest way of simply checking if a string is JSON formatted
 * @param string $json
 * @return mixed
 */
function isJson($json) {
    json_decode($json);
    return (json_last_error() == JSON_ERROR_NONE);
}

/**
 * Convert float or double to 12 digit minor units
 * @param float|double $amount
 * @return string
 */
function floatToMinor($amount) {
    if(is_string($amount)) {
        return 0;//just to cause it to fail
    }

    $number = $amount * 100;
    $zeros = 12 - strlen($number);
    $padding = '';

    for($i=0; $i<$zeros; $i++) {
        $padding .= '0';
    }

    $minor = $padding.$number;
    return $minor;
}

/**
 * Convert minor units to float or double either normal or standard integer
 * @param string $amount
 * @param bool $standard_format
 * @return float|int
 */
function minorToFloat($amount, $standard_format=false) {
    if(!is_string($amount)) {
        return $amount;
    }

    $num = ((int)$amount/100);
    if($standard_format) {
        return (int) ($num * 100);
    }
    return number_format((float) $num, 2,'.','');
}

