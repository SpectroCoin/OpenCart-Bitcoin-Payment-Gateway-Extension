<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Exception;

if (!defined('DIR_APPLICATION')) {
    die('Access denied.');
}

class GenericError extends \Exception
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
