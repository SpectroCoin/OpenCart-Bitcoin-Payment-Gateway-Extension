<?php
class ControllerExtensionPaymentSpectrocoin extends Controller
{
    private $error = array();
    private $langs = array('heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'text_all_zones', 'text_none',
        'text_yes', 'text_no', 'text_off', 'entry_project', 'entry_merchant', 'entry_sign', 'entry_lang', 'help_lang', 'entry_test',
        'entry_order_status', 'entry_geo_zone', 'entry_receive_currency', 'entry_status', 'entry_default_payments', 'entry_display_payments',
        'entry_sort_order', 'help_callback', 'button_save', 'button_cancel', 'tab_general', 'entry_private_key', 'text_default_title', 'entry_title',
        'text_spectrocoin', 'entry_private_key_tooltip'
    );

    public function index() {
        $this->load->model('localisation/order_status');
        $this->language->load('payment/spectrocoin');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if($this->validate()) {
                $this->load->model('setting/setting');
                if (!$this->request->post['spectrocoin_private_key']) {
                    $this->request->post['spectrocoin_private_key'] = $this->config->get('spectrocoin_private_key');
                }
                $this->model_setting_setting->editSetting('spectrocoin', $this->request->post);
                $this->session->data['success'] = $this->language->get('text_success');
				$this->response->redirect($this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true));
            }
        }
        $data = array();
        $data['receive_currency'] = $this->receiveCurrency;
        $this->loadLang($data);

        if (!empty($this->error)) {
            foreach ($this->error as $key=>$error) {
                $data['error_' . $key] = $error;
            }
        }

        $this->loadBreadcrumbs($data);

        $data['action'] = $this->url->link('extension/payment/spectrocoin', 'token=' . $this->session->data['token'], 'SSL');

        $data['cancel'] = HTTPS_SERVER . 'index.php?route=extension/payment&token=' . $this->session->data['token'];

        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_project');
        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_merchant');
        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_title');
        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_private_key');
        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_receive_currency');
        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_status');
        $this->loadFromRequestOrFromConfig($data, 'spectrocoin_sort_order');


        $data['callback'] = HTTP_CATALOG . 'index.php?route=payment/spectrocoin/callback';

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->template = 'extension/payment/spectrocoin.tpl';
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->template, $data));
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
            'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], 'SSL')
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL')
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('payment/spectrocoin', 'token=' . $this->session->data['token'], 'SSL')
        );
    }

    private function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/spectrocoin')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}