<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

class Cancel extends \Opencart\System\Engine\Controller
{
    public function index()
    {

        $this->load->model('checkout/order');
        
        // Check if order_id is set in session, if not, set it here
        if (!isset($this->session->data['order_id'])) {
            // Logic to retrieve and set the order_id if not already set
            if (isset($this->request->get['order_id'])) {
                $this->session->data['order_id'] = (int)$this->request->get['order_id'];
            } else {
                $this->log->write('SpectroCoin Cancel: Order ID is not set in the session or request.');
                $this->response->redirect($this->url->link('checkout/cart'));
                return;
            }
        }

        $order_id = $this->session->data['order_id'];
        $order = $this->model_checkout_order->getOrder($order_id);
        if ($order) {
            $this->model_checkout_order->addOrderHistory($order_id, 7); // 7 - Canceled
        } else {
            $this->log->write('SpectroCoin Cancel: Order not found - Order ID: ' . $order_id);
        }

        $this->language->load('extension/spectrocoin/payment/spectrocoin');
        
        $data = array();
        $data['title'] = sprintf($this->language->get('heading_title'), '/index.php?route=checkout/cart');
        
        if (isset($this->request->server['HTTPS']) && $this->request->server['HTTPS'] == 'on') {
            $data['base'] = HTTPS_SERVER;
        } else {
            $data['base'] = HTTP_SERVER;
        }
        
        $data['continue'] = $this->url->link('checkout/cart');
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_failure'] = $this->language->get('text_failure');
        $data['text_failure_wait'] = $this->language->get('text_failure_wait');
        
        $template = 'extension/spectrocoin/payment/spectrocoin_failure';
        $this->response->setOutput($this->load->view($template, $data));
    }
}
