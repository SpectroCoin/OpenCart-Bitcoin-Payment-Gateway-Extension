<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

if (!defined('DIR_APPLICATION')) {
    die('Access denied.');
}

class SCConfig
{
    const MERCHANT_API_URL = 'https://pp.spectrocoin.com/api/public';
    const AUTH_URL = 'https://pp.spectrocoin.com/api/public/oauth/token';
    const ACCEPTED_FIAT_CURRENCIES = ["EUR", "USD", "PLN", "CHF", "SEK", "GBP", "AUD", "CAD", "CZK", "DKK", "NOK"];
}
