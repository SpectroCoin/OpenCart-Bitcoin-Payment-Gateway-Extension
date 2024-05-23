<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/SCMerchantClient.php';

class Confirm extends \Opencart\System\Engine\Controller
{
    const MERCHANT_API_URL = 'https://test.spectrocoin.com/api/public';
    const AUTH_URL = 'https://test.spectrocoin.com/api/public/oauth/token';
    var $time = 600;

    public function index()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');

        $project_id = $this->config->get('payment_spectrocoin_project');
        $client_id = $this->config->get('payment_spectrocoin_client_id');
        $client_secret = $this->config->get('payment_spectrocoin_client_secret');

        if (!$project_id || !$client_id || !$client_secret) {
            $this->log->write('SpectroCoin Error: in configuration some of the mandatory credentials are not filled.');
            return;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        if ($order['custom_field']) {
            $order_url = $order['custom_field']['url'];
            $time = $order['custom_field']['time'];
            if ($order_url && $time && ($time + $this->time) > time()) {
                header('Location: ' . $order_url);
                exit;
            } else {
                $this->model_checkout_order->addHistory($this->session->data['order_id'], 14);
                header('Location: ' . $this->url->link('common/home'));
                exit;
            }
        }

        $currency = $order['currency_code'];
        $amount = round(($order['total'] * $this->currency->getvalue($order['currency_code'])), 2);
        $order_id = (int)$order['order_id'];
        $description = "Order #{$order_id}";

        $callback_url = $this->url->link('extension/spectrocoin/payment/callback', '', true);
        $success_url = $this->url->link('extension/spectrocoin/payment/accept', '', true);
        $failure_url = $this->url->link('extension/spectrocoin/payment/cancel', '', true);

        $client = new SCMerchantClient($this->registry, $this->session, self::MERCHANT_API_URL, $project_id, $client_id, $client_secret, self::AUTH_URL);
        $order_request = new SpectroCoin_CreateOrderRequest(
            $order_id . "-" . $this->random_str(5),
            $description,
            null,
            'BTC',
            $amount,
            $currency,
            $callback_url,
            $success_url,
            $failure_url
        );
        $response = $client->spectrocoinCreateOrder($order_request);
        if ($response instanceof SpectroCoin_ApiError) {
            $this->log->write('SpectroCoin Error: error during creating order. File: ' . __FILE__ . ' Line: ' . __LINE__);
            $this->api_error($response);
        } else if ($response == null) {
            $this->log->write('SpectroCoin Error: error during creating order, response is null. File: ' . __FILE__ . ' Line: ' . __LINE__);
            $this->api_error('');
        } else {
            $redirect_url = $response->getRedirectUrl();
            $this->model_checkout_order->addHistory($order_id, 1);
            $this->db->query('UPDATE `' . DB_PREFIX . 'order` SET custom_field = \'' . serialize(['url' => $redirect_url, 'time' => time()]) . '\' WHERE order_id = \'' . $order_id . '\'');
            header('Location: ' . $redirect_url);
            exit;
        }
    }

    private function random_str($length)
    {
        return substr(md5(rand(1, pow(2, 16))), 0, $length);
    }

    private function api_error($response)
    {
        $template = 'extension/spectrocoin/payment/spectrocoin_api_error';
        $data['css_path'] = 'extension/spectrocoin/catalog/view/stylesheet/spectrocoin_api_error.css';
        $data['js_path'] = 'extension/spectrocoin/catalog/view/javascript/payment/spectrocoin_api_error.js';
        $data['error_code'] = $response->getCode();
        $data['error_message'] = $response->getMessage();
        $data['shop_link'] = $this->config->get('config_url');

        $this->response->setOutput($this->load->view($template, $data));
    }
}
