<?php

namespace App\Liqpay;

use Psr\Log\LoggerInterface;

class LiqPay
{
    private $_api_url = 'https://www.liqpay.ua/api/';
    private $_checkout_url = 'https://www.liqpay.ua/api/3/checkout';
    protected $_supportedCurrencies = array(
        'EUR', 'USD', 'UAH');
    protected $_supportedLangs = ['uk', 'ru', 'en'];
    private $_public_key;
    private $_private_key;
    private $_server_response_code = null;

    protected $_button_translations = array(
        'ru' => 'Оплатить',
        'uk' => 'Сплатити',
        'en' => 'Pay'
    );
    protected $_actions = array(
        "pay", "hold", "subscribe", "paydonate"
    );
    public $curlRequester;
    private LoggerInterface $logger;


    /**
     * Constructor.
     *
     * @param string $public_key
     * @param string $private_key
     * @param string $api_url (optional)
     *
     * @throws \InvalidArgumentException
     */

    public function __construct(LoggerInterface $logger, $public_key, $private_key, $api_url = null)
    {
        $this->logger = $logger;
        if (empty($public_key)) {
            throw new \InvalidArgumentException('public_key is empty');
        }

        if (empty($private_key)) {
            throw new \InvalidArgumentException('private_key is empty');
        }

        $this->curlRequester = new CurlRequester($this->logger);

        $this->_public_key = $public_key;
        $this->_private_key = $private_key;

        if (null !== $api_url) {
            $this->_api_url = $api_url;
        }
    }

    /**
     * Call API
     *
     * @param string $path
     * @param array $params
     * @param int $timeout
     *
     * @return array|null
     */
    public function api($path, $params = array(), $timeout = 5)
    {
        $params = $this->check_required_params($params);
        $url = $this->_api_url . $path;
        $private_key = $this->_private_key;
        $data = $this->encode_params($params);
        $signature = $this->str_to_sign($private_key . $data . $private_key);
        $postfields = http_build_query(array(
            'data' => $data,
            'signature' => $signature
        ));

        $server_output = $this->curlRequester->make_curl_request($url, $postfields, $timeout);
        $toArray = json_decode($server_output, true);
        $this->logger->info('server_output', $toArray);

        return $toArray;
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

    /**
     * cnb_form
     *
     * @param array $params
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function cnb_form($params)
    {
        $language = 'uk';
        if (isset($params['language']) && in_array($params['language'], $this->_supportedLangs)) {
            $language = $params['language'];
        }

        $params = $this->cnb_params($params);
        $data = $this->encode_params($params);
        $signature = $this->cnb_signature($params);



        return sprintf('
            <form method="POST" action="%s" accept-charset="utf-8">
                %s
                %s
                <script type="text/javascript" src="https://static.liqpay.ua/libjs/sdk_button.js"></script>
                <sdk-button label="%s" background="#77CC5D" onClick="submit()"></sdk-button>
            </form>
            ',
            $this->_checkout_url,
            sprintf('<input type="hidden" name="%s" value="%s" />', 'data', $data),
            sprintf('<input type="hidden" name="%s" value="%s" />', 'signature', $signature),
            $this->_button_translations[$language]
        );
    }

    /**
     * cnb_form raw data for custom form
     *
     * @param $params
     * @return array
     */
    public function cnb_form_raw($params)
    {
        $params = $this->cnb_params($params);

        return array(
            'url' => $this->_checkout_url,
            'data' => $this->encode_params($params),
            'signature' => $this->cnb_signature($params)
        );
    }

    /**
     * cnb_signature
     *
     * @param array $params
     *
     * @return string
     */
    public function cnb_signature($params)
    {
        $params = $this->cnb_params($params);
        $private_key = $this->_private_key;

        $json = $this->encode_params($params);
        $signature = $this->str_to_sign($private_key . $json . $private_key);

        return $signature;
    }

    protected function check_required_params($params)
    {
        $params['public_key'] = $this->_public_key;

        if (!isset($params['version'])) {
            throw new \InvalidArgumentException('version is null');
        }

        if (!isset($params['action'])) {
            throw new \InvalidArgumentException('action is null');
        }
        return $params;
    }
    /**
     * cnb_params
     *
     * @param array $params
     *
     * @return array $params
     */
    protected function cnb_params($params)
    {
        $params = $this->check_required_params($params);

        if (!isset($params['amount'])) {
            throw new \InvalidArgumentException('amount is null');
        }

        if (!isset($params['currency'])) {
            throw new \InvalidArgumentException('currency is null');
        }
        if (!in_array($params['currency'], $this->_supportedCurrencies)) {
            throw new \InvalidArgumentException('currency is not supported');
        }

        if (!isset($params['description'])) {
            throw new \InvalidArgumentException('description is null');
        }


        return $params;
    }

    /**
     * encode_params
     *
     * @param array $params
     * @return string
     */
    protected function encode_params($params)
    {
        return base64_encode(json_encode($params));
    }

    /**
     * decode_params
     *
     * @param string $params
     * @return array
     */
    public function decode_params($params)
    {
        return json_decode(base64_decode($params), true);
    }

    /**
     * str_to_sign
     *
     * @param string $str
     *
     * @return string
     */
    public function str_to_sign($str)
    {
        $signature = base64_encode(sha1($str, 1));

        return $signature;
    }
}