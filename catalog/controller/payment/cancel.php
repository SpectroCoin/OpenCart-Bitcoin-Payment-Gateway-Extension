<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

use Opencart\System\Engine\Controller;

class Cancel extends Controller
{
    /**
     * Landing page for the payer after a failed or abandoned payment.
     *
     * Presentation only. Order status is owned exclusively by the callback
     * controller, which proves the request came from SpectroCoin — the JSON
     * path re-fetches the authoritative status from the API, and the legacy
     * POST path verifies the payload signature. A payer-facing redirect target
     * carries no such proof and is reachable by anyone, so it must never write
     * order history.
     *
     * FAILED and EXPIRED both arrive on that authenticated channel and are
     * already handled there, so nothing is lost by not cancelling here.
     */
    public function index()
    {
        $this->loadFailurePage();
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
