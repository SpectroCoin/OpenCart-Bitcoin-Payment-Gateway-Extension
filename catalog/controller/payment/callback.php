<?php
namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/Http/OrderCallback.php';
require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/Enum/OrderStatus.php';

use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Http\OrderCallback;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Enum\OrderStatus;

use Exception;
use InvalidArgumentException;

use GuzzleHttp\Exception\RequestException;

use Opencart\System\Engine\Controller;

class Callback extends Controller
{
    public function index()
    {
        try{
            $this->load->model('checkout/order');
            if ($_SERVER['REQUEST_METHOD'] != 'POST') {
                $this->log->write('SpectroCoin Error: Invalid request method, POST is required');
                exit;
            }
    
            $order_callback = $this->initCallbackFromPost();
            if (!$order_callback) {
                $this->log->write('SpectroCoin Error: Invalid callback data');
                exit;
            }
            $order_id = explode("-", ($order_callback->getOrderId()))[0];
            $order = $this->model_checkout_order->getOrder($order_id);    
            $status = $order_callback->getStatus();
            if ($order){
                switch ($status) {
                    case OrderStatus::New->value:
                        break;
                    case OrderStatus::Pending->value:
                        $this->model_checkout_order->addHistory($order_id, 2);
                        break;
                    case OrderStatus::Expired->value:
                        $this->model_checkout_order->addHistory($order_id, 14);
                        break;
                    case OrderStatus::Failed->value:
                        $this->model_checkout_order->addHistory($order_id, 7);
                        break;
                    case OrderStatus::Paid->value:
                        $this->model_checkout_order->addHistory($order_id, 15);
                        break;
                    default:
                        $this->log->write('SpectroCoin Callback: Unknown order status - ' . $status);
                        echo 'Unknown order status: ' . $status;
                        exit;
                }
                http_response_code(200);
                echo '*ok*';
                exit;
            }
            else{
                $this->log->write('SpectroCoin Error: Order not found - Order ID: ' . $order_id);
                http_response_code(404); // Not Found
                exit;
            }
        } catch (RequestException $e) {
			$this->log->write("Callback API error: {$e->getMessage()}");
			http_response_code(500); // Internal Server Error
			echo esc_html__('Callback API error', 'spectrocoin-accepting-bitcoin');
			exit;
		} catch (InvalidArgumentException $e) {
			$this->log->write("Error processing callback: {$e->getMessage()}");
			http_response_code(400); // Bad Request
			echo esc_html__('Error processing callback', 'spectrocoin-accepting-bitcoin');
			exit;
		} catch (Exception $e) {
			$this->log->write('error', "Error processing callback: {$e->getMessage()}");
			http_response_code(500); // Internal Server Error
			echo esc_html__('Error processing callback', 'spectrocoin-accepting-bitcoin');
			exit;
		}
    }

    /**
	 * Initializes the callback data from POST request.
	 * 
	 * @return OrderCallback|null Returns an OrderCallback object if data is valid, null otherwise.
	 */
	private function initCallbackFromPost(): ?OrderCallback
	{
		$expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];

		$callback_data = [];
		foreach ($expected_keys as $key) {
			if (isset($_POST[$key])) {
				$callback_data[$key] = $_POST[$key];
			}
		}

		if (empty($callback_data)) {
            $this->log->write("No data received in callback");
			return null;
		}
		return new OrderCallback($callback_data);
	}

}
