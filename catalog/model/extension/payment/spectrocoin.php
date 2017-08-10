<?php

class ModelExtensionPaymentSpectrocoin extends Model {
    public function getMethod($address) {
        $this->load->language('extension/payment/spectrocoin');

        if ($this->config->get('payment_spectrocoin_status')) {
            $status = true;
        } else {
            $status = false;
        }
        
        $method_data = array();

        if ($status) {
            $title = $this->language->get('payment_spectrocoin_title') ? $this->config->get('payment_spectrocoin_title') : $this->language->get('text_default_title');
            $method_data = array(
                'code'       => 'spectrocoin',
                'title'      => $title,
                'terms'      => '',
                'sort_order' => $this->config->get('payment_spectrocoin_sort_order')
            );
        }
        return $method_data;
    }
}
