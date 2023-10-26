<?php

namespace Opencart\Catalog\Model\Extension\Spectrocoin\Payment;
class Spectrocoin extends \Opencart\System\Engine\Model {
    public function getMethods($address) {
        $this->load->language('extension/spectrocoin/payment/spectrocoin');

        if ($this->config->get('payment_spectrocoin_status')) {
            $status = true;
        } else {
            $status = false;
        }
        
        $method_data = array();

        $option_data = [];

        $option_data['spectrocoin'] = [
            'code' => 'spectrocoin.spectrocoin',
            'name' => $this->config->get('payment_spectrocoin_title')
        ];

        if ($status) {
            $title = $this->language->get('payment_spectrocoin_title') ? $this->config->get('payment_spectrocoin_title') : $this->language->get('text_default_title');
            $method_data = array(
                'code'       => 'spectrocoin',
                'name'      => $title,
                'option'      => $option_data,
                'sort_order' => $this->config->get('payment_spectrocoin_sort_order')
            );
        }
        return $method_data;
    }
}
