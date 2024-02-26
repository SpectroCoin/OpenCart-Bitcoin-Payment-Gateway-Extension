<?php

namespace Opencart\Admin\Controller\Extension\Spectrocoin\Payment;
class Spectrocoin extends \Opencart\System\Engine\Controller
{
    private $error = array();
    private $langs = array('heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'text_all_zones', 'text_none',
        'text_yes', 'text_no', 'text_off', 'entry_project', 'entry_client_id', 'entry_client_secret', 'entry_sign', 'entry_lang', 'help_lang', 'entry_test',
        'entry_order_status', 'entry_geo_zone', 'entry_receive_currency', 'entry_status', 'entry_default_payments', 'entry_display_payments',
        'entry_sort_order', 'button_save', 'button_cancel', 'tab_general', 'text_default_title', 'entry_title',
        'text_spectrocoin' , 'info_heading', 'info_desc', 'info_step_1', 'info_step_2', 'info_step_3', 
        'info_step_4', 'info_step_5', 'info_step_6', 'info_step_7', 'info_step_8', 'info_step_9', 'info_step_10', 'info_note', 'status_checkbox_label'
    );

    public function index() {
        $this->load->model('localisation/order_status');
        $this->load->language('extension/spectrocoin/payment/spectrocoin');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');
        $this->document->addStyle('view/stylesheet/spectrocoin.css');
        if ($this->request->server['REQUEST_METHOD'] == 'POST') {    
            if ($this->validate()) {
                $this->load->model('setting/setting');
                $this->request->post['spectrocoin_private_key'] = $privateKey;
                $this->model_setting_setting->editSetting('payment_spectrocoin', $this->request->post);
                $this->session->data['success'] = $this->language->get('text_success');
    
                $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
            }
        }
        $data = array();
        $this->loadLang($data);

        if (!empty($this->error)) {
            foreach ($this->error as $key=>$error) {
                $data['error_' . $key] = $error;
            }
        }

        $this->loadBreadcrumbs($data);
        $data['action'] = $this->url->link('extension/spectrocoin/payment/spectrocoin', 'user_token=' . $this->session->data['user_token'], 'SSL');
        $data['cancel'] = HTTP_SERVER . 'index.php?route=extension/payment&user_token=' . $this->session->data['user_token'];

        $this->loadFromRequestOrFromConfig($data, 'payment_spectrocoin_title');
        $this->loadFromRequestOrFromConfig($data, 'payment_spectrocoin_project');
        $this->loadFromRequestOrFromConfig($data, 'payment_client_id');
        $this->loadFromRequestOrFromConfig($data, 'payment_client_secret');
        $this->loadFromRequestOrFromConfig($data, 'payment_spectrocoin_status');
        $this->loadFromRequestOrFromConfig($data, 'payment_spectrocoin_sort_order');
        $data['callback'] = HTTP_CATALOG . 'index.php?route=payment/spectrocoin/callback';
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/spectrocoin/payment/spectrocoin', $data));
    }

    private function loadLang(&$data) {
        foreach ($this->langs as $lang) {
            $data[$lang] = $this->language->get($lang);
        }
    }

    private function loadFromRequestOrFromConfig(&$data, $key) {
        if (isset($this->request->post[$key])) {
            $data[$key] = $this->request->post[$key];
        } else {
            $data[$key] = $this->config->get($key);
        }
    }

    private function loadBreadcrumbs(&$data) {
        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], 'SSL')
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('extension/payment', 'user_token=' . $this->session->data['user_token'], 'SSL')
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/spectrocoin/payment/spectrocoin', 'user_token=' . $this->session->data['user_token'], 'SSL')
        );
    }

    private function validate() {
        if (!$this->user->hasPermission('modify', 'extension/spectrocoin/payment/spectrocoin')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}