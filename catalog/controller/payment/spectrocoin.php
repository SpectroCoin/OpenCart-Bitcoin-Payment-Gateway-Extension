<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;
use Exception;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/SCMerchantClient.php';


class Spectrocoin extends \Opencart\System\Engine\Controller
{
	const MERCHANT_API_URL = 'https://test.spectrocoin.com/api/public';
    const AUTH_URL = 'https://test.spectrocoin.com/api/public/oauth/token';
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
        $project_id = $this->config->get('payment_spectrocoin_project');
        $client_id = $this->config->get('payment_spectrocoin_client_id');
        $client_secret = $this->config->get('payment_spectrocoin_client_secret');
        
        if (!$project_id || !$client_id || !$client_secret) {
            $this->log->write('SpectroCoin Error: in configuration some of the mandatory credentials are not filled.');
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        if ($order['custom_field']) {
            $order_url = $order['custom_field']['url'];
            $time = $order['custom_field']['time'];
            if ($order_url && $time && ($time + $this->time) > time()) {
                header('Location: ' . $order_url);
            } 
            else {
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], 14);;
                header('Location: ' . $this->url->link('common/home'));
                exit;
            }
        }

        $currency = $order['currency_code'];
        $amount =  round(($order['total'] * $this->currency->getvalue($order['currency_code'])),2);
        $order_id = $order['order_id'];
        $description = "Order #{$order_id}";

        $ngrok_test_url = "https://3a0c-88-119-150-219.ngrok-free.app";

        //TESTING TO-DO: change back to HTTP_SERVER
        $callback_url = $ngrok_test_url . 'index.php?route=extension/spectrocoin/payment/spectrocoin/callback';
        $success_url = $ngrok_test_url . 'index.php?route=extension/spectrocoin/payment/spectrocoin/accept';
        $failure_url = $ngrok_test_url . 'index.php?route=extension/spectrocoin/payment/spectrocoin/cancel';


        $client = new SCMerchantClient($this->registry, self::MERCHANT_API_URL, $project_id, $client_id, $client_secret, self::AUTH_URL);
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
            $this->log->write('SpectroCoin Error: error during creating order.'." File: " . __FILE__ . " Line: " . __LINE__ );
            $this->api_error($response); 
        } 
        else if($response == null){
            $this->log->write('SpectroCoin Error: error during creating order, response is null' . " File: " . __FILE__ . " Line: " . __LINE__ );
            $this->api_error('');
        } 
        else {
            $redirect_url = $response->getRedirectUrl();
            //Order status Pending
            $this->model_checkout_order->addHistory($order_id, 1);
            $this->db->query('UPDATE `' . DB_PREFIX . 'order` SET custom_field =\'' . serialize(array('url' => $redirect_url, 'time' => time())) . '\' WHERE order_id=\'' . $order_id . '\'');
            header('Location: ' . $redirect_url);
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
        $expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];

        $project_id = $this->config->get('payment_spectrocoin_project');
        $client_id = $this->config->get('payment_spectrocoin_client_id');
        $client_secret = $this->config->get('payment_spectrocoin_client_secret');

        $this->load->model('checkout/order');
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            exit;
        }
        $client = new SCMerchantClient($this->registry, self::MERCHANT_API_URL, $project_id, $client_id, $client_secret, self::AUTH_URL);

        $post_data = [];
		foreach ($expected_keys as $key) {
			if (isset($_REQUEST[$key])) {
				$post_data[$key] = $_REQUEST[$key]; //TODO gali buti kad $_POST
			}
		}
		$callback = $client->spectrocoinProcessCallback($post_data);

        $order_id = $callback->getOrderId();
        $order = $this->model_checkout_order->getOrder($order_id);
        switch ($callback->getStatus()) {
            case SpectroCoin_OrderStatusEnum::$Test:
                break;
            case SpectroCoin_OrderStatusEnum::$New:
                break;
            case SpectroCoin_OrderStatusEnum::$Pending:
                $this->model_checkout_order->addOrderHistory($order_id, 2); // 2 - Processing
                break;
            case SpectroCoin_OrderStatusEnum::$Expired:
                $this->model_checkout_order->addOrderHistory($order_id, 14); // 14 - Expired
                break;
            case SpectroCoin_OrderStatusEnum::$Failed:
                $this->model_checkout_order->addOrderHistory($order_id, 7); // 7 - Canceled
                break;
            case SpectroCoin_OrderStatusEnum::$Paid:
                $this->model_checkout_order->addOrderHistory($order_id, 15); // 15 - Processed
                break;
            default:
                echo 'Unknown order status: ' . $callback->getStatus();
                exit;
        }
        echo '*ok*';
    }

    public function api_error($response) {

        $template = 'extension/spectrocoin/payment/spectrocoin_api_error';
        $data['css_path'] = 'extension/spectrocoin/catalog/view/stylesheet/spectrocoin_api_error.css';
        $data['js_path'] = 'extension/spectrocoin/catalog/view/javascript/payment/spectrocoin_api_error.js';
        $data['error_code'] = $response->getCode();
        $data['error_message'] = $response->getMessage();
        $data['shop_link'] = $this->config->get('config_url');

        $this->response->setOutput($this->load->view($template, $data));
    }

    /**
	 * Generate random string
	 * @param int $length
	 * @return string
	 */
	private function random_str($length)
	{
		return substr(md5(rand(1, pow(2, 16))), 0, $length);
	}

}