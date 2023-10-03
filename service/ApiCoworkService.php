<?php

namespace Dolibarr\Cowork;
class ApiCoworkService {

	public ?\stdClass $user = null;

	public function __construct()
	{
	}

	public function fetchUser(): void {
		global $conf;

		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST . '/login',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => json_encode([
				'email' => $conf->global->COWORK_API_USER,
				'password' => $conf->global->COWORK_API_PASSWORD,
			]),
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json'
			),
		));

		$user_string = curl_exec($curl);

		curl_close($curl);
		$this->user = json_decode($user_string);
	}

	public function getBasketPayed(): array {
		global $conf;

		try {
			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/baskets/payed',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTPHEADER => array(
					'Authorization: Bearer '.$this->user->token
				),
			));

			$json = curl_exec($curl);

			curl_close($curl);

			return json_decode($json);

		}
		catch(\Exception $exception) {
			dol_syslog(get_class($this).'::getBasketPayed '.$exception->getMessage());
			return [];
		}
	}

	public function setInvoiceRef($basketId, $invoiceRef): void {
		global $conf;

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/basket/billed/'.$basketId.'/'.$invoiceRef,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => '',
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$this->user->token
			),
		));

		curl_exec($curl);
	}

	public function getTodayReservations(): array {
		global $conf;

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/place/reservations/today',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$this->user->token
			),
		));

		$json = curl_exec($curl);

		curl_close($curl);

		return json_decode($json);
	}

}
