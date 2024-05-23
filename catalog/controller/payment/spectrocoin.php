<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/SCMerchantClient.php';

use Exception;

class Spectrocoin extends \Opencart\System\Engine\Controller
{
    const MERCHANT_API_URL = 'https://test.spectrocoin.com/api/public';
    const AUTH_URL = 'https://test.spectrocoin.com/api/public/oauth/token';
    private $time = 600;

    public function index()
    {
        $this->language->load('extension/spectrocoin/payment/spectrocoin');
        $data['action'] = $this->url->link('extension/spectrocoin/payment/spectrocoin.confirm', '', true);
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_back'] = $this->language->get('button_back');
        
        if ($this->request->get['route'] != 'checkout/guest/confirm') {
            $data['back'] = HTTP_SERVER . 'index.php?route=checkout/payment';
        } else {
            $data['back'] = HTTP_SERVER . 'index.php?route=checkout/guest';
        }

        $this->load->model('checkout/order');

        $template_path = $this->config->get('config_template') 
            ? $this->config->get('config_template') . '/template/extension/spectrocoin/payment/spectrocoin' 
            : 'extension/spectrocoin/payment/spectrocoin';

        return $this->load->view($template_path, $data);
    }

    public function confirm()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');

        $project_id = $this->config->get('payment_spectrocoin_project');
        $client_id = $this->config->get('payment_spectrocoin_client_id');
        $client_secret = $this->config->get('payment_spectrocoin_client_secret');
        
        if (!$project_id || !$client_id || !$client_secret) {
            $this->log->write('SpectroCoin Error: Some mandatory credentials are not filled in the configuration.');
            return;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $custom_fields = !empty($order['custom_field']) ? unserialize($order['custom_field']) : [];
        $order_url = $custom_fields['url'] ?? null;
        $time = $custom_fields['time'] ?? null;

        if ($order_url && $time && ($time + $this->time) > time()) {
            header('Location: ' . $order_url);
            exit;
        } else {
            $this->model_checkout_order->addHistory($this->session->data['order_id'], 14);
            header('Location: ' . $this->url->link('common/home'));
            exit;
        }

        $currency = $order['currency_code'];
        $amount = round(($order['total'] * $this->currency->getValue($order['currency_code'])), 2);
        $order_id = $order['order_id'];
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
            $this->log->write('SpectroCoin Error: Error during order creation.' . " File: " . __FILE__ . " Line: " . __LINE__);
            $this->api_error($response); 
        } else if ($response == null) {
            $this->log->write('SpectroCoin Error: Error during order creation, response is null.' . " File: " . __FILE__ . " Line: " . __LINE__);
            $this->api_error('');
        } else {
            $redirect_url = $response->getRedirectUrl();
            $this->model_checkout_order->addHistory($order_id, 1);
            $this->db->query('UPDATE `' . DB_PREFIX . 'order` SET custom_field =\'' . serialize(['url' => $redirect_url, 'time' => time()]) . '\' WHERE order_id=\'' . $order_id . '\'');
            header('Location: ' . $redirect_url);
            exit;
        }
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

    private function random_str($length)
    {
        return substr(md5(rand(1, pow(2, 16))), 0, $length);
    }
}
