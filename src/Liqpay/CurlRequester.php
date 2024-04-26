<?php

namespace App\Liqpay;

use Psr\Log\LoggerInterface;

class CurlRequester
{
    private $_server_response_code;
    private string $curl_error;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->_server_response_code = null;
        $this->curl_error = '';
    }


    /**
     * make_curl_request
     * @param $url string
     * @param $postfields string
     * @param int $timeout
     * @return bool|string
     */
    public function make_curl_request($url, $postfields, $timeout = 5) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Avoid MITM vulnerability http://phpsecurity.readthedocs.io/en/latest/Input-Validation.html#validation-of-input-sources
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // Check the existence of a common name and also verify that it matches the hostname provided
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);   // The number of seconds to wait while trying to connect
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);          // The maximum number of seconds to allow cURL functions to execute
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        $this->_server_response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->logger->info('_server_response_code', ['response' => $this->_server_response_code]);
        $this->curl_error = curl_error($ch);
        $this->logger->info('curl_error', ['curl_error' => $this->curl_error]);
        curl_close($ch);
        return $server_output;
    }

    /**
     * Return last api response http code
     *
     * @return string|null
     */
    public function get_response_code()
    {
        return $this->_server_response_code;
    }
}