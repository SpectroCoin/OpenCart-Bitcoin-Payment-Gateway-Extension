<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once DIR_SYSTEM . 'library/spectrocoin/SCMerchantClient.php';

class ControllerPaymentSpectrocoin extends Controller {

    public function index() {

        $data['action']         = $this->url->link('payment/spectrocoin/confirm', '', 'SSL');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_back']    = $this->language->get('button_back');

        $this->language->load('payment/spectrocoin');

        if ($this->request->get['route'] != 'checkout/guest/confirm') {
            $data['back'] = HTTPS_SERVER . 'index.php?route=checkout/payment';
        } else {
            $data['back'] = HTTPS_SERVER . 'index.php?route=checkout/guest';
        }

        $this->load->model('checkout/order');
        $order  = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $amount = ceil($order['total'] * $this->currency->getvalue($order['currency_code']) * 100);

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/spectrocoin.tpl')) {
            return $this->load->view($this->config->get('config_template') . '/template/payment/spectrocoin.tpl', $data);
        } else {
            return $this->load->view('default/template/payment/spectrocoin.tpl', $data);
        }


    }

    public function confirm() {
        $privateKey = $this->config->get('spectrocoin_private_key');
        $receiveCurrency = $this->config->get('spectrocoin_receive_currency');
        $merchantId = $this->config->get('spectrocoin_merchant');
        $appId = $this->config->get('spectrocoin_project');
        if (!$privateKey || !$receiveCurrency || !$merchantId || !$appId) {
            $this->scError('Check admin panel');
        }
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $currency = $order['currency_code'];
        $amount = $order['total'];
        $orderId = $order['order_id'];
        if ($currency != $receiveCurrency) {
            $receiveAmount = $this->unitConversion($amount, $currency, $receiveCurrency);
        } else {
            $receiveAmount = $amount;
        }

        if (!$receiveAmount || $receiveAmount < 0) {
            $this->scError('Unit conversion failed');
        }
        $orderDescription = "Order #{$orderId}";
        $callbackUrl = HTTPS_SERVER . 'index.php?route=payment/spectrocoin/callback';
        $successUrl = HTTPS_SERVER . 'index.php?route=payment/spectrocoin/accept';
        $cancelUrl = HTTPS_SERVER . 'index.php?route=payment/spectrocoin/cancel';

        $client = new SCMerchantClient('', '', $merchantId, $appId);
        $client->setPrivateKey($privateKey);
        $orderRequest = new CreateOrderRequest($orderId, 0, $receiveAmount, $orderDescription, "en", $callbackUrl, $successUrl, $cancelUrl);
        $response = $client->createOrder($orderRequest);

        if ($response instanceof ApiError) {
            throw new Exception('Spectrocoin error. Error code: ' . $response->getCode() . '. Message: ' . $response->getMessage());
        } else {
            if ($response->getReceiveCurrency() != $receiveCurrency) {
                throw new Exception('Currencies does not match');
            } else {
                $redirectUrl = $response->getRedirectUrl();
                header('Location: ' . $redirectUrl);
            }
        }
    }

    public function accept() {
        if (isset($this->session->data['token'])) {
            $this->response->redirect(HTTPS_SERVER . 'index.php?route=checkout/success&token=' . $this->session->data['token']);
        } else {
            $this->response->redirect(HTTPS_SERVER . 'index.php?route=checkout/success');
        }
    }

    public function cancel() {        
        $this->language->load('payment/spectrocoin');
        $data = array();
        $data['title'] = sprintf($this->language->get('heading_title'), '/index.php?route=checkout/cart');
        if(isset($this->request->server['HTTPS']) and $this->request->server['HTTPS'] == 'on') {
            $data['base'] = HTTPS_SERVER;
        } else {
            $data['base'] = HTTP_SERVER;
        }
        $data['continue'] = '/index.php?route=checkout/cart';
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_failure'] = $this->language->get('text_failure');
        $data['text_failure_wait'] = $this->language->get('text_failure_wait');
        $template = 'default/template/payment/spectrocoin_failure.tpl';
        $this->response->setOutput($this->load->view($template, $data));
    }

    public function callback() {
        $privateKey = $this->config->get('spectrocoin_private_key');
        $receiveCurrency = $this->config->get('spectrocoin_receive_currency');
        $merchantId = $this->config->get('spectrocoin_merchant');
        $appId = $this->config->get('spectrocoin_project');
        $this->load->model('checkout/order');

        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            exit;
        }
        $client = new SCMerchantClient('', '', $merchantId, $appId);
        $client->setPrivateKey($privateKey);
        $callback = $client->parseCreateOrderCallback($_REQUEST);
        if ($client->validateCreateOrderCallback($callback)) {
            if ($receiveCurrency != $callback->getReceiveCurrency()) {
                echo 'Receive currency does not match.';
                exit;
            }
            $orderId = $callback->getOrderId();
            $order = $this->model_checkout_order->getOrder($orderId);
            switch ($callback->getStatus()) {
                case OrderStatusEnum::$Test:
                    break;
                case OrderStatusEnum::$New:
                    break;
                case OrderStatusEnum::$Pending:
                    $this->model_checkout_order->addOrderHistory($orderId, 1); // 1 - Pending
                    break;
                case OrderStatusEnum::$Expired:
                    $this->model_checkout_order->addOrderHistory($orderId, 14); // 7 - Canceled
                    break;
                case OrderStatusEnum::$Failed:
                    $this->model_checkout_order->addOrderHistory($orderId, 7); // 7 - Canceled
                    break;
                case OrderStatusEnum::$Paid:
                    $this->model_checkout_order->addOrderHistory($orderId, 15); // 15 - Processed
                    break;
                default:
                    echo 'Unknown order status: '.$callback->getStatus();
                    exit;
            }
            echo '*ok*';
        }
    }

    private function unitConversion($amount, $currencyFrom, $currencyTo)
    {
        $currencyFrom = strtoupper($currencyFrom);
        $currencyTo = strtoupper($currencyTo);
        $url = "http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20yahoo.finance.xchange%20where%20pair%20in%20%28%22{$currencyTo}{$currencyFrom}%22%20%29&env=store://datatables.org/alltableswithkeys&format=json";
        $content = file_get_contents($url);
        if ($content) {
            $obj = json_decode($content);
            if (!isset($obj->error) && isset($obj->query->results->rate->Rate)) {
                $rate = $obj->query->results->rate->Rate;
                return ($amount * 1.0) / $rate;
            }
        }
        Mage::throwException(Mage::helper('payment')->__('Spectrocoin currency conversion failed. Please select different payment'));
    }
}
