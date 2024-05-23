<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/SCMerchantClient.php';

class Callback extends \Opencart\System\Engine\Controller
{
    const MERCHANT_API_URL = 'https://test.spectrocoin.com/api/public';
    const AUTH_URL = 'https://test.spectrocoin.com/api/public/oauth/token';

    public function index()
    {
        $expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];

        $project_id = $this->config->get('payment_spectrocoin_project');
        $client_id = $this->config->get('payment_spectrocoin_client_id');
        $client_secret = $this->config->get('payment_spectrocoin_client_secret');

        $this->load->model('checkout/order');
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            $this->log->write('SpectroCoin Callback: Invalid request method');
            exit;
        }

        $client = new SCMerchantClient($this->registry, $this->session, self::MERCHANT_API_URL, $project_id, $client_id, $client_secret, self::AUTH_URL);

        $post_data = [];
        foreach ($expected_keys as $key) {
            if (isset($_POST[$key])) {
                $post_data[$key] = $_POST[$key];
            }
        }

        $this->log->write('Callback data BEFORE processing - ' . json_encode($post_data));

        $callback = $client->spectrocoinProcessCallback($post_data);
        $this->log->write('Callback data AFTER processing - ' . json_encode($post_data));
        if (!$callback) {
            $this->log->write('SpectroCoin Callback: Invalid callback data');
            exit;
        }

        $order_id = (int) $callback->getOrderId();
        $order = $this->model_checkout_order->getOrder($order_id);

        if (!$order) {
            $this->log->write('SpectroCoin Callback: Order not found - Order ID: ' . $order_id);
            exit;
        }

        $status = $callback->getStatus();

        switch ($status) {
            case SpectroCoin_OrderStatusEnum::$New:
                break;
            case SpectroCoin_OrderStatusEnum::$Pending:
                $this->model_checkout_order->addHistory($order_id, 2);
                break;
            case SpectroCoin_OrderStatusEnum::$Expired:
                $this->model_checkout_order->addHistory($order_id, 14);
                break;
            case SpectroCoin_OrderStatusEnum::$Failed:
                $this->model_checkout_order->addHistory($order_id, 7);
                break;
            case SpectroCoin_OrderStatusEnum::$Paid:
                $this->model_checkout_order->addHistory($order_id, 15);
                break;
            default:
                $this->log->write('SpectroCoin Callback: Unknown order status - ' . $status);
                echo 'Unknown order status: ' . $status;
                exit;
        }

        echo '*ok*';
    }
}
