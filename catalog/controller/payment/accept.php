<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

class Accept extends \Opencart\System\Engine\Controller
{
    public function index()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        if (isset($this->session->data['user_token'])) {
            $this->response->redirect(HTTP_SERVER . 'index.php?route=checkout/success&user_token=' . $this->session->data['user_token']);
        } else {
            $this->response->redirect(HTTP_SERVER . 'index.php?route=checkout/success');
        }
    }
}
