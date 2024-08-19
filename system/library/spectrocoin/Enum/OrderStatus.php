<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Enum;

if (!defined('DIR_APPLICATION')) {
    die('Access denied.');
}

enum OrderStatus: int {
	case New = 1;
	case Pending = 2;
	case Paid = 3;
	case Failed = 4;
	case Expired = 5;
}