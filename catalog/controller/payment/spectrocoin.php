<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

use Opencart\System\Engine\Controller;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\SCMerchantClient;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Utils;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Exception\ApiError;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Exception\GenericError;

class Spectrocoin extends Controller
{
    public function index(): string
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
        } else {
            return $this->load->view('extension/spectrocoin/payment/spectrocoin', $data);
        }
    }

    public function confirm(): void
    {
        $project_id = $this->config->get('payment_spectrocoin_project');
        $client_id = $this->config->get('payment_spectrocoin_client_id');
        $client_secret = $this->config->get('payment_spectrocoin_client_secret');

        if (empty($project_id)) {
            $this->log->write('SpectroCoin Error: The project ID is missing in the configuration.');
            return;
        }

        if (empty($client_id)) {
            $this->log->write('SpectroCoin Error: The client ID is missing in the configuration.');
            return;
        }

        if (empty($client_secret)) {
            $this->log->write('SpectroCoin Error: The client secret is missing in the configuration.');
            return;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);

        if (!empty($order['custom_field'])) {
            $order_url = $order['custom_field']['url'] ?? null;
            if ($order_url) {
                header('Location: ' . $order_url);
                exit;
            }
        }

        $sc_merchant_client = new SCMerchantClient(
            $this->registry,
            $this->session,
            $project_id,
            $client_id,
            $client_secret
        );

        $order_id = $order['order_id'] . "-" . Utils::generateRandomStr(6);
        $order_data = [
            'orderId' => $order_id,
            'description' => "Order #{$order_id} from " . get_site_url(),
            'receiveAmount' => round(($order['total'] * $this->currency->getValue($order['currency_code'])), 2),
            'receiveCurrencyCode' => $order['currency_code'],
            'callbackUrl' => $this->url->link('extension/spectrocoin/payment/callback', '', true),
            'successUrl' => $this->url->link('extension/spectrocoin/payment/accept', '', true),
            'failureUrl' => $this->url->link('extension/spectrocoin/payment/cancel', '', true),
        ];

        $response = $sc_merchant_client->createOrder($order_data);

        if ($response instanceof ApiError || $response instanceof GenericError) {
            $this->log->write('SpectroCoin Error: Error during creating order.' . " File: " . __FILE__ . " Line: " . __LINE__);
            $this->api_error($response);
        } else {
            $redirect_url = $response->getRedirectUrl();
            $this->model_checkout_order->addHistory($order_id, 1);
            $this->db->query('UPDATE `' . DB_PREFIX . 'order` SET custom_field =\'' . serialize(['url' => $redirect_url]) . '\' WHERE order_id=\'' . $order_id . '\'');
            header('Location: ' . $redirect_url);
        }
    }

    public function api_error(ApiError|GenericError $response): void
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
