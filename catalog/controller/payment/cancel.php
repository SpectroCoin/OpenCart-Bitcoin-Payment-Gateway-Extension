<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

use Opencart\System\Engine\Controller;

class Cancel extends Controller
{
    public function index()
    {
        $this->load->model('checkout/order');

        $order_id = $this->getOrderId();

        if ($order_id !== null) {
            $order = $this->model_checkout_order->getOrder($order_id);
            if ($order) {
                $this->model_checkout_order->addHistory($order_id, 7); // 7 - Canceled
            } else {
                $this->log->write('SpectroCoin Error: Invalid Order ID.');
            }
        } else {
            $this->log->write('SpectroCoin Error: Order ID is not available.');
        }

        $this->loadFailurePage();
    }

    /**
     * Retrieves the order ID from the session or request.
     *
     * @return int|null The order ID if found, or null otherwise.
     */
    private function getOrderId(): ?int
    {
        if (isset($this->session->data['order_id'])) {
            return (int)$this->session->data['order_id'];
        }

        if (isset($this->request->get['order_id'])) {
            return (int)$this->request->get['order_id'];
        }

        $this->load->model('account/order');
        $orders = $this->model_account_order->getOrders();
        if (!empty($orders)) {
            return (int)$orders[0]['order_id'];
        }

        return null;
    }

    /**
     * Loads the failure page to be displayed to the user after order cancellation.
     *
     * @return void
     */
    private function loadFailurePage(): void
    {
        $this->language->load('extension/spectrocoin/payment/spectrocoin');

        $data = [];
        $data['title']             = sprintf($this->language->get('heading_title'), '/index.php?route=checkout/cart');
        $data['base']              = $this->getBaseUrl();
        $data['continue']          = $this->url->link('checkout/cart');
        $data['heading_title']     = $this->language->get('heading_title');
        $data['text_failure']      = $this->language->get('text_failure');
        $data['text_failure_wait'] = $this->language->get('text_failure_wait');

        $template = 'extension/spectrocoin/payment/spectrocoin_failure';
        $this->response->setOutput($this->load->view($template, $data));
    }

    /**
     * Retrieves the base URL depending on whether HTTPS is used.
     *
     * @return string The base URL.
     */
    private function getBaseUrl(): string
    {
        return isset($this->request->server['HTTPS']) && $this->request->server['HTTPS'] === 'on' 
            ? HTTPS_SERVER 
            : HTTP_SERVER;
    }
}
