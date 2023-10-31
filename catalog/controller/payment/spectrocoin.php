<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;
use Exception;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/SCMerchantClient.php';


class Spectrocoin extends \Opencart\System\Engine\Controller
{
	const merchantApiUrl = 'https://spectrocoin.com/api/merchant/1';
    var $time = 600;

    public function index()
    {
        $data['action'] = $this->url->link('extension/spectrocoin/payment/spectrocoin.confirm', '', true);
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_back'] = $this->language->get('button_back');
        $this->language->load('extension/spectrocoin/payment/spectrocoin');
        if ($this->request->get['route'] != 'checkout/guest/confirm') {
            $data['back'] = HTTP_SERVER . 'index.php?route=checkout/payment';
        } else {
            $data['back'] = HTTP_SERVER . 'index.php?route=checkout/guest';
        }
        $this->load->model('checkout/order');
        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/spectrocoin/payment/spectrocoin')) {
            return $this->load->view($this->config->get('config_template') . '/template/extension/spectrocoin/payment/spectrocoin', $data);
        } 
        else{
            return $this->load->view('extension/spectrocoin/payment/spectrocoin', $data);
        }
    }

    public function confirm()
    {
        $privateKey = $this->config->get('payment_spectrocoin_private_key');
        $userId = $this->config->get('payment_spectrocoin_merchant');
        $appId = $this->config->get('payment_spectrocoin_project');

        if (!$privateKey || !$userId || !$appId) {
            $this->scError('Check admin panel');
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        if ($order['custom_field']) {
            $orderUrl = $order['custom_field']['url'];
            $time = $order['custom_field']['time'];
            if ($orderUrl && $time && ($time + $this->time) > time()) {
                header('Location: ' . $orderUrl);
            } 
            else {
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], 14);;
                header('Location: ' . $this->url->link('common/home'));
                exit;
            }
        }

        $currency = $order['currency_code'];
        $amount =  round(($order['total'] * $this->currency->getvalue($order['currency_code'])),2);
        $orderId = $order['order_id'];
        $orderDescription = "Order #{$orderId}";
        $callbackUrl = HTTP_SERVER . 'index.php?route=extension/spectrocoin/payment/spectrocoin/callback';
        $successUrl = HTTP_SERVER . 'index.php?route=extension/spectrocoin/payment/spectrocoin/accept';
        $cancelUrl = HTTP_SERVER . 'index.php?route=extension/spectrocoin/payment/spectrocoin/cancel';
        $client = new SCMerchantClient(self::merchantApiUrl, $userId, $appId);
        $client->setPrivateMerchantKey($privateKey);
        $orderRequest = new CreateOrderRequest(null, "BTC", null, $currency, $amount, $orderDescription, "en", $callbackUrl, $successUrl, $cancelUrl);
        $response = $client->createOrder($orderRequest);

        if ($response instanceof ApiError) {
            $this->apierror($response); 
        }  
        else {
            $redirectUrl = $response->getRedirectUrl();
            //Order status Pending
            $this->model_checkout_order->addHistory($orderId, 1);
            $this->db->query('UPDATE `' . DB_PREFIX . 'order` SET custom_field =\'' . serialize(array('url' => $redirectUrl, 'time' => time())) . '\' WHERE order_id=\'' . $orderId . '\'');
            header('Location: ' . $redirectUrl);
        }
    }

    public function accept()
    {
        if (isset($this->session->data['user_token'])) {
            $this->response->redirect(HTTP_SERVER . 'index.php?route=checkout/success&user_token=' . $this->session->data['user_token']);
        } else {
            $this->response->redirect(HTTP_SERVER . 'index.php?route=checkout/success');
        }
    }

    public function cancel()
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        if ($order) {
            $this->model_checkout_order->addOrderHistory($order['order_id'], 7); // Canceled
        }
        $this->language->load('payment/spectrocoin');
        $data = array();
        $data['title'] = sprintf($this->language->get('heading_title'), '/index.php?route=checkout/cart');
        if (isset($this->request->server['HTTPS']) and $this->request->server['HTTPS'] == 'on') {
            $data['base'] = HTTP_SERVER;
        }
        else
        {
            $data['base'] = HTTP_SERVER;
        }
        $data['continue'] = HTTP_SERVER . '/index.php?route=checkout/cart';
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_failure'] = $this->language->get('text_failure');
        $data['text_failure_wait'] = $this->language->get('text_failure_wait');
        $template = 'extension/spectrocoin/payment/spectrocoin_failure';
        $this->response->setOutput($this->load->view($template, $data));
    }

    public function callback() {
        $privateKey = $this->config->get('payment_spectrocoin_private_key');
        $receiveCurrency = $this->config->get('payment_spectrocoin_receive_currency');
        $userId = $this->config->get('payment_spectrocoin_merchant');
        $appId = $this->config->get('payment_spectrocoin_project');
        $this->load->model('checkout/order');
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            exit;
        }
        $client = new SCMerchantClient(self::merchantApiUrl, $userId, $appId);
        $client->setPrivateMerchantKey($privateKey);
        $callback = $client->parseCreateOrderCallback($_REQUEST);
        $orderId = $callback->getOrderId();
        $order = $this->model_checkout_order->getOrder($orderId);
        switch ($callback->getStatus()) {
            case OrderStatusEnum::$Test:
                break;
            case OrderStatusEnum::$New:
                break;
            case OrderStatusEnum::$Pending:
                $this->model_checkout_order->addOrderHistory($orderId, 2); // 2 - Processing
                break;
            case OrderStatusEnum::$Expired:
                $this->model_checkout_order->addOrderHistory($orderId, 14); // 14 - Expired
                break;
            case OrderStatusEnum::$Failed:
                $this->model_checkout_order->addOrderHistory($orderId, 7); // 7 - Canceled
                break;
            case OrderStatusEnum::$Paid:
                $this->model_checkout_order->addOrderHistory($orderId, 15); // 15 - Processed
                break;
            default:
                echo 'Unknown order status: ' . $callback->getStatus();
                exit;
        }
        echo '*ok*';
    }

    public function apierror($response) {
        $template = 'extension/spectrocoin/payment/spectrocoin_api_error';
        $data['css_path'] = 'extension/spectrocoin/catalog/view/stylesheet/spectrocoin_api_error.css';
        $data['error_code'] = $response->getCode();
        $data['error_message'] = $response->getMessage();
        $data['shop_link'] = $this->config->get('config_url');
        $data['error_causes'] = $this->getCausesByErrorCode($response->getCode());

        $this->response->setOutput($this->load->view($template, $data));
    }

    public function getCausesByErrorCode($errorCode){
        switch ($errorCode) {
            case 2:
                return '<li>Check your private key</li>';
                break;
            case 3:
                return '<li>Your shop FIAT currency is not supported by SpectroCoin, change it if possible</li>';
                break;
            case 6:
                return '<li>Check your merchantApiId and userId</li>';
                break;
            case 99:
                return '<li>Incorrect url</li>
                <li>Incorrect Parameters or Data Format</li>
                <li>Required Parameters Missing</li>
                <li>Unsupported currency</li>';
                break;
            default:
                return '';
                break;
        }
    }
}