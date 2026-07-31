<?php

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/Http/OrderCallback.php';
require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/Http/OldOrderCallback.php';
require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/Enum/OrderStatus.php';
require_once DIR_EXTENSION . 'spectrocoin/system/library/spectrocoin/SCMerchantClient.php';

use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Http\OrderCallback;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Http\OldOrderCallback;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Enum\OrderStatus;
use Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\SCMerchantClient;

use Exception;
use InvalidArgumentException;

use Opencart\System\Engine\Controller;

class Callback extends Controller
{
    public function index()
    {
        try {
            $this->load->model('checkout/order');

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->log->write('SpectroCoin Error: Invalid request method, POST is required');
                http_response_code(405);
                exit;
            }

            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $callback = $this->initCallbackFromJson();
                if (! $callback) {
                    throw new InvalidArgumentException('Invalid JSON callback payload');
                }

                $project_id = $this->config->get('payment_spectrocoin_project');
                $client_id = $this->config->get('payment_spectrocoin_client_id');
                $client_secret = $this->config->get('payment_spectrocoin_client_secret');

                $sc_merchant_client = new SCMerchantClient(
                    $this->registry,
                    $this->session,
                    $project_id,
                    $client_id,
                    $client_secret
                );

                $order_data = $sc_merchant_client->getOrderById($callback->getUuid());

                if (! is_array($order_data) || empty($order_data['orderId']) || empty($order_data['status'])) {
                    throw new InvalidArgumentException('Malformed order data from API');
                }

                $order_id = explode('-', ($order_data['orderId']))[0];
                $raw_status = $order_data['status'];
                // MerchantOrderDTO reports the settlement side as
                // receiveAmount / receiveCurrencyCode.
                $receive_amount = $order_data['receiveAmount'] ?? null;
                $receive_currency = $order_data['receiveCurrencyCode'] ?? null;
            } else {
                $callback = $this->initCallbackFromPost();
                $order_id = explode('-', ($callback->getOrderId()))[0];
                $raw_status = $callback->getStatus();
                $receive_amount = $callback->getReceiveAmount();
                $receive_currency = $callback->getReceiveCurrency();
            }

            if (!$callback) {
                $this->log->write('SpectroCoin Error: Invalid callback data');
                http_response_code(400);
                exit;
            }

            $order_id = (int) explode('-', $order_id, 2)[0];

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                $this->log->write('SpectroCoin Error: Order not found - ' . $order_id);
                http_response_code(404);
                exit;
            }

            // The order must actually have been placed through this extension, so
            // a callback for one order cannot settle an unrelated one. The field
            // holding the method differs between OpenCart releases, so only a
            // positive mismatch is treated as an error.
            $order_payment_code = '';
            if (isset($order_info['payment_method']['code'])) {
                $order_payment_code = (string) $order_info['payment_method']['code'];
            } elseif (isset($order_info['payment_code'])) {
                $order_payment_code = (string) $order_info['payment_code'];
            }

            if ($order_payment_code !== '' && strpos($order_payment_code, 'spectrocoin') === false) {
                $this->log->write('SpectroCoin Error: Order was not paid with this payment method - ' . $order_id);
                http_response_code(400);
                exit;
            }

            // The order was created with receiveAmount / receiveCurrencyCode
            // derived from the order total, so they must still match. A missing
            // field means an unexpected payload shape rather than a mismatch, so
            // it is logged and the comparison is skipped.
            if ($receive_currency === null || $receive_amount === null) {
                $this->log->write('SpectroCoin: callback carried no settlement amount to compare - ' . $order_id);
            } else {
                $expected_currency = (string) $order_info['currency_code'];
                $expected_amount = round(((float) $order_info['total']) * $this->currency->getValue($expected_currency), 2);

                if (strtoupper((string) $receive_currency) !== strtoupper($expected_currency)) {
                    $this->log->write('SpectroCoin Error: callback currency does not match order - ' . $order_id);
                    http_response_code(400);
                    exit;
                }
                // Reported for now rather than rejected: it is not yet confirmed
                // whether the settled amount is gross or net of fees, and
                // rejecting a legitimate settlement would leave the order unpaid.
                // Promote to a rejection once confirmed.
                if ((float) $receive_amount + 0.00000001 < $expected_amount) {
                    $this->log->write('SpectroCoin: callback amount ' . $receive_amount . ' does not cover order ' . $order_id . ' total ' . $expected_amount);
                }
            }

            $statusEnum = OrderStatus::normalize($raw_status);
            switch ($statusEnum) {
                case OrderStatus::NEW:
                    break;
                case OrderStatus::PENDING:
                    $this->model_checkout_order->addHistory($order_id, 2);
                    break;
                case OrderStatus::PAID:
                    $this->model_checkout_order->addHistory($order_id, 15);
                    break;
                case OrderStatus::FAILED:
                    $this->model_checkout_order->addHistory($order_id, 7);
                    break;
                case OrderStatus::EXPIRED:
                    $this->model_checkout_order->addHistory($order_id, 14);
                    break;
                default:
                    $this->log->write('SpectroCoin Callback: Unhandled status - ' . $statusEnum->value);
                    http_response_code(500);
                    exit;
            }

            http_response_code(200);
            echo '*ok*';
            exit;
        } catch (\JsonException $e) {
            $this->log->write('SpectroCoin Error: JSON parse error - ' . $e->getMessage());
            http_response_code(400);
            exit;
        } catch (\InvalidArgumentException $e) {
            $this->log->write('SpectroCoin Error: Invalid callback data - ' . $e->getMessage());
            http_response_code(400);
            exit;
        } catch (\Exception $e) {
            $this->log->write('SpectroCoin Error: ' . $e->getMessage());
            http_response_code(500);
            exit;
        }
    }


    /**
     * Initializes the callback data from POST (form-encoded) request.
     * 
     * Callback format processed by this method is URL-encoded form data.
     * Example: merchantId=1387551&apiId=105548&userId=…&sign=…
     * Content-Type: application/x-www-form-urlencoded
     * These callbacks are being sent by old merchant projects.
     *
     * Extracts the expected fields from `$_POST`, validates the signature,
     * and returns an `OldOrderCallback` instance wrapping that data.
     *
     * @deprecated since v2.1.0
     *
     * @return OldOrderCallback|null  An `OldOrderCallback` if the POST body
     *                                contained valid data; `null` otherwise.
     */
    private function initCallbackFromPost(): ?OldOrderCallback
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
        return new OldOrderCallback($callback_data);
    }


    /**
     * Initializes the callback data from JSON request body.
     *
     * Reads the raw HTTP request body, decodes it as JSON, and returns
     * an OrderCallback instance if the payload is valid.
     *
     * @return OrderCallback|null  An OrderCallback if the JSON payload
     *                             contained valid data; null if the body
     *                             was empty.
     *
     * @throws \JsonException           If the request body is not valid JSON.
     * @throws \InvalidArgumentException If required fields are missing
     *                                   or validation fails in OrderCallback.
     *
     */
    private function initCallbackFromJson(): ?OrderCallback
    {
        $body = (string) \file_get_contents('php://input');
        if ($body === '') {
            $this->log->write('Empty JSON callback payload');
            return null;
        }

        $data = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            $this->log->write('JSON callback payload is not an object');
            return null;
        }

        return new OrderCallback(
            $data['id'] ?? null,
            $data['merchantApiId'] ?? null
        );
    }
}
