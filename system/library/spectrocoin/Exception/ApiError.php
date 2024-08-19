<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Exception;

include_once('GenericError.php');

if (!defined('DIR_APPLICATION')) {
    die('Access denied.');
}

class ApiError extends GenericError
{
    /**
     * @param string $message
     * @param int $code
     */
    function __construct($message, $code = 0)
    {
        parent::__construct($message, $code);
    }
}