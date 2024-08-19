<?php

namespace Opencart\Catalog\Model\Extension\Spectrocoin\Payment;

use Opencart\System\Engine\Model;

class Spectrocoin extends Model {
    public function getMethods(array $address): array {
        $this->load->language('extension/spectrocoin/payment/spectrocoin');
        
        $status = $this->config->get('payment_spectrocoin_status') ? true : false;
        
        $method_data = [];

        $option_data = [];

        $option_data['spectrocoin'] = [
            'code' => 'spectrocoin.spectrocoin',
            'name' => $this->config->get('payment_spectrocoin_title')
        ];

        if ($status) {
            $title = $this->language->get('payment_spectrocoin_title') ? $this->config->get('payment_spectrocoin_title') : $this->language->get('text_default_title');
            $method_data = [
                'code'       => 'spectrocoin',
                'name'       => $title,
                'option'     => $option_data,
                'sort_order' => $this->config->get('payment_spectrocoin_sort_order')
            ];
        }
        return $method_data;
    }
}
