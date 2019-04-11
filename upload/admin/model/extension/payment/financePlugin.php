<?php

use Divido\MerchantSDK\Client;
use Divido\MerchantSDK\Environment;
use Divido\MerchantSDK\Handlers\ApiRequestOptions;
use Divido\MerchantSDK\Exceptions\MerchantApiBadResponseException;

class ModelExtensionPaymentFinancePlugin extends Model {
	const CACHE_KEY_PLANS = 'finance_plugin_plans';

	private $sdk;

	public function __construct($registry){
		require_once(DIR_SYSTEM.'/library/autoload.php');
		parent::__construct($registry);
	}

	public function getEnvironment($apiKey)
	{
		if (empty($apiKey)) {
			return false;
		} else {
			list($environment, $key) = explode("_", $apiKey);
			$environment = strtoupper($environment);
			if (!is_null(
				constant("Divido\MerchantSDK\Environment::$environment")
			)) {
				$environment
					= constant("Divido\MerchantSDK\Environment::$environment");
				return $environment;
			} else {
				return false;
			}
		}
	}

	public function instantiateSDK(string $apiKey, $environment = false)
	{
		$environment = ($environment) ? $environment : $this->getEnvironment($apiKey);

		$httpClient = new \GuzzleHttp\Client();

		$guzzleClient = new \Divido\MerchantSDKGuzzle5\GuzzleAdapter($httpClient);

		$httpClientWrapper =  new \Divido\MerchantSDK\HttpClient\HttpClientWrapper($guzzleClient,
			\Divido\MerchantSDK\Environment::CONFIGURATION[$environment]['base_uri'],
			$apiKey
		);

		$sdk = new Client(
			$httpClientWrapper,
			$environment
		);

		return $sdk;
	}

	public function getLastStatus($order_id) {
		$last_status = $this->db->query("select `comment` from `".DB_PREFIX."order_history` WHERE `order_id`={$order_id} AND `comment` LIKE 'Status:%' ORDER BY `date_added` DESC LIMIT 1");
		if($last_status->num_rows == 1) {
			$status = substr($last_status->rows[0]['comment'], 8);
			return $status;
		}else return false;
	}

	public function getAllPlans($api_key = null) {

		if(is_null($this->sdk)){
			$api_key = $api_key ?? $this->config->get('payment_financePlugin_api_key');

			if (!$api_key) {
				throw new Exception("No Finance Plugin api-key defined");
			}

			$this->sdk = $this->instantiateSDK($api_key);
		}

		$requestOptions = new ApiRequestOptions();

		try {
			$response = $this->sdk->getAllPlans($requestOptions);

			$plans = $response->getResources();
			// OpenCart 2.1 switched to json for their file storage cache, so
			// we need to convert to a simple object.
			$plans_plain = array();
			foreach ($plans as $plan) {
				$plan_copy = new stdClass();
				$plan_copy->id = $plan->id;
				$plan_copy->text = $plan->description;
				$plan_copy->country = $plan->country->name;
				$plan_copy->min_amount = $plan->credit_amount->minimum_amount;
				$plan_copy->min_deposit = $plan->deposit->minimum_percentage;
				$plan_copy->max_deposit = $plan->deposit->maximum_percentage;
				$plan_copy->interest_rate = $plan->interest_rate_percentage;
				$plan_copy->deferral_period = $plan->deferral_period_months;
				$plan_copy->agreement_duration = $plan->agreement_duration_months;

				$plans_plain[] = $plan_copy;
			}
			$this->cache->set(self::CACHE_KEY_PLANS, $plans_plain);

			return $plans_plain;
		} catch (MerchantApiBadResponseException $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function getEnvironmentFromSDK($api_key=null) {
		if(is_null($this->sdk)){
			$api_key = $api_key ?? $this->config->get('payment_financePlugin_api_key');

			if (!$api_key) {
				throw new Exception("No Finance Plugin api-key defined");
			}

			$this->sdk = $this->instantiateSDK($api_key);
		}

		$requestOptions = (new ApiRequestOptions());
		  // Retrieve all finance plans for the merchant.
		try{
			$response = $this->sdk->platformEnvironments()->getPlatformEnvironment();
			$response_array = json_decode($response->getBody()->getContents(), true);
		}catch(MerchantApiBadResponseException $e){
			$errorMessage = SDKErrorHandler::getMessage($e);
			throw new Exception($e->getMessage());
		}
		return $response_array['data']['environment'];
	}

	public function activateOrder($order_id) {
		if(is_null($this->sdk)){
			$api_key = $this->config->get('payment_financePlugin_api_key');

			if (!$api_key) {
				throw new Exception("No Finance Plugin api-key defined");
			}

			$this->sdk = $this->instantiateSDK($api_key);
		}

		$orderQuery = $this->db->query("
			SELECT 
				`".DB_PREFIX."order`.*, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`salt`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`proposal_id`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`application_id`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`deposit_amount` 
			FROM 
				`".DB_PREFIX."order` 
				INNER JOIN 
					`".DB_PREFIX."c8UMbuNcJ4_lookup` 
						ON `".DB_PREFIX."c8UMbuNcJ4_lookup`.`order_id` = `".DB_PREFIX."order`.`order_id`
			WHERE 
				`".DB_PREFIX."order`.`order_id` = '{$order_id}'
			LIMIT 1");
		
		if($orderQuery->num_rows !== 1) {
			throw new Exception("Could not find order");
		} else $order = $orderQuery->rows[0];
		
		// First get the application you wish to create an activation for.
		$application = (new \Divido\MerchantSDK\Models\Application())
			->withId($order['application_id']);

		$items = [];
		$itemsQuery = $this->db->query("
		SELECT
			`name`,
			`quantity`,
			`price`
		FROM
			`".DB_PREFIX."order_product`
		WHERE
			`order_id` = '{$order_id}'
		");
		
		if($itemsQuery->num_rows > 0) {
			foreach($itemsQuery->rows as $item) {
				$items[] = [
					'name' => $item['name'],
					'quantity' => intval($item['quantity']),
					'price' => $item['price']*100
				];
			}
		}

		// Create a new application activation model.
		$applicationActivation = (new \Divido\MerchantSDK\Models\ApplicationActivation())
			->withAmount(number_format($order['total'],2)*100)
			->withReference("Order ".$order['application_id'])
			->withComment('Order was dispatched by merchant.')
			->withOrderItems($items)
			->withDeliveryMethod('delivery');

		// Create a new activation for the application.
		$response = $this->sdk->applicationActivations()->createApplicationActivation($application, $applicationActivation);

		$activationResponseBody = $response->getBody()->getContents();

		return json_decode($activationResponseBody);
	}

	public function refundOrder($order_id) {
		if(is_null($this->sdk)){
			$api_key = $this->config->get('payment_financePlugin_api_key');

			if (!$api_key) {
				throw new Exception("No Finance Plugin api-key defined");
			}

			$this->sdk = $this->instantiateSDK($api_key);
		}

		$orderQuery = $this->db->query("
			SELECT 
				`".DB_PREFIX."order`.*, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`salt`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`proposal_id`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`application_id`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`deposit_amount` 
			FROM 
				`".DB_PREFIX."order` 
				INNER JOIN 
					`".DB_PREFIX."c8UMbuNcJ4_lookup` 
						ON `".DB_PREFIX."c8UMbuNcJ4_lookup`.`order_id` = `".DB_PREFIX."order`.`order_id`
			WHERE 
				`".DB_PREFIX."order`.`order_id` = '{$order_id}'
			LIMIT 1");
		
		if($orderQuery->num_rows !== 1) {
			throw new Exception("Could not find order");
		} else $order = $orderQuery->rows[0];
		
		// First get the application you wish to create an activation for.
		$application = (new \Divido\MerchantSDK\Models\Application())
			->withId($order['application_id']);

		$items = [];
		$itemsQuery = $this->db->query("
		SELECT
			`name`,
			`quantity`,
			`price`
		FROM
			`".DB_PREFIX."order_product`
		WHERE
			`order_id` = '{$order_id}'
		");
		
		if($itemsQuery->num_rows > 0) {
			foreach($itemsQuery->rows as $item) {
				$items[] = [
					'name' => $item['name'],
					'quantity' => intval($item['quantity']),
					'price' => $item['price']*100
				];
			}
		}

		// Create a new application refund model.
		$applicationRefund = (new \Divido\MerchantSDK\Models\ApplicationRefund())
			->withAmount(number_format($order['total'],2)*100)
			->withReference("Order ".$order['application_id'])
			->withComment('As per merchant request.')
			->withOrderItems($items);

		// Create a new refund for the application.
		$response = $this->sdk->applicationRefunds()->createApplicationRefund($application, $applicationRefund);

		$refundResponseBody = $response->getBody()->getContents();

		return json_decode($refundResponseBody);
	}

	public function cancelOrder($order_id) {
		if(is_null($this->sdk)){
			$api_key = $this->config->get('payment_financePlugin_api_key');

			if (!$api_key) {
				throw new Exception("No Finance Plugin api-key defined");
			}

			$this->sdk = $this->instantiateSDK($api_key);
		}

		$orderQuery = $this->db->query("
			SELECT 
				`".DB_PREFIX."order`.*, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`salt`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`proposal_id`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`application_id`, 
				`".DB_PREFIX."c8UMbuNcJ4_lookup`.`deposit_amount` 
			FROM 
				`".DB_PREFIX."order` 
				INNER JOIN 
					`".DB_PREFIX."c8UMbuNcJ4_lookup` 
						ON `".DB_PREFIX."c8UMbuNcJ4_lookup`.`order_id` = `".DB_PREFIX."order`.`order_id`
			WHERE 
				`".DB_PREFIX."order`.`order_id` = '{$order_id}'
			LIMIT 1");
		
		if($orderQuery->num_rows !== 1) {
			throw new Exception("Could not find order");
		} else $order = $orderQuery->rows[0];
		
		// First get the application you wish to create an activation for.
		$application = (new \Divido\MerchantSDK\Models\Application())
			->withId($order['application_id']);

		$items = [];
		$itemsQuery = $this->db->query("
		SELECT
			`name`,
			`quantity`,
			`price`
		FROM
			`" . DB_PREFIX . "order_product`
		WHERE
			`order_id` = '{$order_id}'
		");
		
		if($itemsQuery->num_rows > 0) {
			foreach($itemsQuery->rows as $item) {
				$items[] = [
					'name' => $item['name'],
					'quantity' => intval($item['quantity']),
					'price' => $item['price']*100
				];
			}
		}

		// Create a new application refund model.
		$applicationCancellation = (new \Divido\MerchantSDK\Models\ApplicationCancellation())
			->withAmount(number_format($order['total'],2)*100)
			->withReference("Order ".$order['application_id'])
			->withComment('As per merchant request.')
			->withOrderItems($items);

		// Create a new cancellation for the application.
		$response = $this->sdk->applicationCancellations()->createApplicationCancellation($application, $applicationCancellation);

		$cancellationResponseBody = $response->getBody()->getContents();

		return json_decode($cancellationResponseBody);
	}

	public function getLookupByOrderId($order_id) {
		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "c8UMbuNcJ4_lookup` WHERE `order_id` = " . (int)$order_id);
	}

	public function install() {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "c8UMbuNcJ4_product` (
				`product_id` INT(11) NOT NULL,
				`display` CHAR(7) NOT NULL,
				`plans` text,
				PRIMARY KEY (`product_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;");

		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "c8UMbuNcJ4_lookup` (
				`order_id` INT(11) NOT NULL,
				`salt` CHAR(64) NOT NULL,
				`proposal_id` CHAR(40),
				`application_id` CHAR(40),
				`deposit_amount` NUMERIC(6,2),
			  PRIMARY KEY (`order_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;");
	}

	public function uninstall() {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "c8UMbuNcJ4_product`;");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "c8UMbuNcJ4_lookup`;");
	}
}
