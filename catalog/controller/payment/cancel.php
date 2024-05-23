<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

class Cancel extends \Opencart\System\Engine\Controller
{
    public function index()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');

        $this->load->model('checkout/order');
        
        if (isset($this->session->data['order_id'])) {
            $order_id = $this->session->data['order_id'];
            $order = $this->model_checkout_order->getOrder($order_id);
            if ($order) {
                $this->model_checkout_order->addOrderHistory($order_id, 7); // 7 - Canceled
            }
        } else {
            $this->log->write('SpectroCoin Cancel: Order ID is not set in the session.');
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
