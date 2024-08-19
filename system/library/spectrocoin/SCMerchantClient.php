<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Spectrocoin\Payment;

if (!defined('DIR_APPLICATION')) {
    die('Access denied.');
}

include_once('Config.php');
include_once('Utils.php');
include_once('Exception/ApiError.php');
include_once('Exception/GenericError.php');
include_once('Http/CreateOrderRequest.php');
include_once('Http/CreateOrderResponse.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

use InvalidArgumentException;
use Exception;
use RuntimeException;

require __DIR__ . '/../../../vendor/autoload.php';

class SCMerchantClient
{
    private $opencart_registry;
	private $opencart_session;

    private string $project_id;
    private string $client_id;
    private string $client_secret;
    private string $encryption_key;
    protected Client $http_client;

    /**
     * Constructor
     * 
     * @param string $project_id
     * @param string $client_id
     * @param string $client_secret
     */
    public function __construct($opencart_registry, $opencart_session, string $project_id, string $client_id, string $client_secret)
    {
        $this->opencart_registry = $opencart_registry;
		$this->opencart_session = $opencart_session;

        $this->project_id = $project_id;
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->http_client = new Client();

        $uniqueKeyParts = [
			$this->opencart_registry->get('config')->get('config_encryption'), // OpenCart's own encryption key
			DB_PREFIX,
		];
	
		$uniqueKeyParts = array_filter($uniqueKeyParts, function($value) { return !empty($value); });
	
		if (!empty($uniqueKeyParts)) {
			$this->encryption_key = hash('sha256', implode(':', $uniqueKeyParts));
		} else {
			throw new Exception('Failed to generate an encryption key.');
		}

    }

    /**
     * Create an order
     * 
     * @param array $order_data
     * @return CreateOrderResponse|ApiError|GenericError|null
     */
    public function createOrder(array $order_data)
    {
        $access_token_data = $this->getAccessTokenData();

        if (!$access_token_data || $access_token_data instanceof ApiError) {
            return $access_token_data;
        }

        try {
            $create_order_request = new CreateOrderRequest($order_data);
        } catch (InvalidArgumentException $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        }

        $order_payload = $create_order_request->toArray();
        $order_payload['projectId'] = $this->project_id;

        return $this->sendCreateOrderRequest(json_encode($order_payload));
    }

    /**
     * Send create order request
     * 
     * @param string $order_payload
     * @return CreateOrderResponse|ApiError|GenericError
     */
    private function sendCreateOrderRequest(string $order_payload)
    {
        try {
            $response = $this->http_client->request('POST', Config::MERCHANT_API_URL . '/merchants/orders/create', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->getAccessTokenData()['access_token'],
                    'Content-Type' => 'application/json'
                ],
                RequestOptions::BODY => $order_payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to parse JSON response: ' . json_last_error_msg());
            }

            $responseData = [
                'preOrderId' => $body['preOrderId'] ?? null,
                'orderId' => $body['orderId'] ?? null,
                'validUntil' => $body['validUntil'] ?? null,
                'payCurrencyCode' => $body['payCurrencyCode'] ?? null,
                'payNetworkCode' => $body['payNetworkCode'] ?? null,
                'receiveCurrencyCode' => $body['receiveCurrencyCode'] ?? null,
                'payAmount' => $body['payAmount'] ?? null,
                'receiveAmount' => $body['receiveAmount'] ?? null,
                'depositAddress' => $body['depositAddress'] ?? null,
                'memo' => $body['memo'] ?? null,
                'redirectUrl' => $body['redirectUrl'] ?? null
            ];

            return new CreateOrderResponse($responseData);
        } catch (InvalidArgumentException $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        } catch (RequestException $e) {
            return new ApiError($e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Retrieves the current access token data
     * 
     * @return array|null
     */
    public function getAccessTokenData()
    {
        $current_time = time();
        $encrypted_access_token_data = $this->retrieveEncryptedData();
        if ($encrypted_access_token_data) {
            $access_token_data = json_decode(Utils::DecryptAuthData($encrypted_access_token_data, $this->encryption_key), true);
            if ($this->isTokenValid($access_token_data, $current_time)) {
                return $access_token_data;
            }
        }
        return $this->refreshAccessToken($current_time);
    }

    /**
     * Refreshes the access token
     * 
     * @param int $current_time
     * @return array|ApiError
     * @throws RequestException
     */
    public function refreshAccessToken(int $current_time): array|ApiError
    {
        try {
            $response = $this->http_client->post(Config::AUTH_URL, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                ],
            ]);
    
            $access_token_data = json_decode((string) $response->getBody(), true);
            if (!isset($access_token_data['access_token'], $access_token_data['expires_in'])) {
                return new ApiError('Invalid access token response');
            }
    
            $access_token_data['expires_at'] = $current_time + $access_token_data['expires_in'];
            $encrypted_access_token_data = Utils::encryptAuthData(json_encode($access_token_data), $this->encryption_key);
    
            $this->storeEncryptedData($encrypted_access_token_data);
    
            return $access_token_data;
    
        } catch (RequestException $e) {
            return new ApiError($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Checks if the current access token is valid
     * 
     * @param array $access_token_data
     * @param int $current_time
     * @return bool
     */
    private function isTokenValid(array $access_token_data, int $current_time): bool
    {
        return isset($access_token_data['expires_at']) && $current_time < $access_token_data['expires_at'];
    }

    /**
     * Stores encrypted authentication token data in the session.
     *
     * @param string $encrypted_access_token_data The encrypted token data to be stored.
     */
    private function storeEncryptedData(string $encrypted_access_token_data): void
    {
        $this->opencart_session->data['spectrocoin_auth_token'] = $encrypted_access_token_data;
    }

    /**
     * Retrieves encrypted authentication token data from the session.
     *
     * @return string|null The encrypted token data if it exists in the session, null otherwise.
     */
    private function retrieveEncryptedData(): ?string
    {
        return $this->opencart_session->data['spectrocoin_auth_token'] ?? null;
    }
}
