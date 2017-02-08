<?php
require_once DIR_SYSTEM . 'library/spectrocoin/SCMerchantClient.php';
class ControllerExtensionPaymentSpectrocoin extends Controller
{
	const merchantApiUrl = 'https://spectrocoin.com/api/merchant/1';
    var $time = 600;
    public function index()
    {
        $data['action'] = $this->url->link('extension/payment/spectrocoin/confirm', '', 'SSL');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_back'] = $this->language->get('button_back');
        $this->language->load('extension/payment/spectrocoin');
        if ($this->request->get['route'] != 'checkout/guest/confirm') {
            $data['back'] = HTTPS_SERVER . 'index.php?route=checkout/payment';
        } else {
            $data['back'] = HTTPS_SERVER . 'index.php?route=checkout/guest';
        }
        $this->load->model('checkout/order');
        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/spectrocoin.tpl')) {
            return $this->load->view($this->config->get('config_template') . '/template/extension/payment/spectrocoin.tpl', $data);
        } else
        {
            return $this->load->view('extension/payment/spectrocoin.tpl', $data);
        }
    }
    public function confirm()
    {
        $privateKey = $this->config->get('spectrocoin_private_key');
        $merchantId = $this->config->get('spectrocoin_merchant');
        $appId = $this->config->get('spectrocoin_project');
        if (!$privateKey || !$merchantId || !$appId) {
            $this->scError('Check admin panel');
        }
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        if ($order['custom_field']) {
            $time = $order['custom_field']['time'];
            $orderUrl = $order['custom_field']['url'];
            if ($orderUrl && $time && ($time + $this->time) > time()) {
                header('Location: ' . $orderUrl);
            } else {
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], 14);;
                header('Location: ' . $this->url->link('common/home'));
                exit;
            }
        }
        $currency = $order['currency_code'];
        $amount =  round(($order['total'] * $this->currency->getvalue($order['currency_code'])),2);
        $orderId = $order['order_id'];
        $orderDescription = "Order #{$orderId}";
        $callbackUrl = HTTPS_SERVER . 'index.php?route=extension/payment/spectrocoin/callback';
        $successUrl = HTTPS_SERVER . 'index.php?route=extension/payment/spectrocoin/accept';
        $cancelUrl = HTTPS_SERVER . 'index.php?route=extension/payment/spectrocoin/cancel';
        $client = new SCMerchantClient(self::merchantApiUrl, $merchantId, $appId);
        $client->setPrivateMerchantKey($privateKey);
        $orderRequest = new CreateOrderRequest(null, "BTC", null, $currency, $amount, $orderDescription, "en", $callbackUrl, $successUrl, $cancelUrl);
        $response = $client->createOrder($orderRequest);
                if ($response instanceof ApiError) {
            throw new Exception('Spectrocoin error. Error code: ' . $response->getCode() . '. Message: ' . $response->getMessage());
        }  else {
                $redirectUrl = $response->getRedirectUrl();
                //Order status Pending
                $this->model_checkout_order->addOrderHistory($orderId, 1);
                $this->db->query('UPDATE `' . DB_PREFIX . 'order` SET custom_field =\'' . serialize(array('url' => $redirectUrl, 'time' => time())) . '\' WHERE order_id=\'' . $orderId . '\'');
                header('Location: ' . $redirectUrl);
            }
        }
        public function accept()
    {
        if (isset($this->session->data['token'])) {
            $this->response->redirect(HTTPS_SERVER . 'index.php?route=checkout/success&token=' . $this->session->data['token']);
        } else {
            $this->response->redirect(HTTPS_SERVER . 'index.php?route=checkout/success');
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
            $data['base'] = HTTPS_SERVER;
        }
        else
        {
            $data['base'] = HTTP_SERVER;
        }
        $data['continue'] = HTTPS_SERVER . '/index.php?route=checkout/cart';
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_failure'] = $this->language->get('text_failure');
        $data['text_failure_wait'] = $this->language->get('text_failure_wait');
        $template = 'extension/payment/spectrocoin_failure.tpl';
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
        $client = new SCMerchantClient(self::merchantApiUrl, $merchantId, $appId);
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
                    echo 'Unknown order status: '.$callback->getStatus();
                    exit;
            }
            echo '*ok*';
        }
    }
}