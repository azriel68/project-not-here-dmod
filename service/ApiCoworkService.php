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

		curl_setopt_array($curl, array( //TODO factorize curl
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
				'Content-Type: application/json',
				'Coworkid: '.$conf->global->COWORK_ID
			),
		));

		$user_string = curl_exec($curl);

		curl_close($curl);
		$this->user = json_decode($user_string);
	}

	public function getPaymentsPayed(): array {
		global $conf;

		try {
			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/payments/payed',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTPHEADER => array(
					'Authorization: Bearer '.$this->user->token,
					'Coworkid: '.$conf->global->COWORK_ID
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

	public function setInvoiceRef($paymentId, $invoiceRef, $filepath): mixed {
		global $conf;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/payment/billed/'.$paymentId,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => json_encode([
				'invoice_path'=>$filepath,
				'invoice_ref' =>$invoiceRef,
			]),
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$this->user->token,
				'Coworkid: '.$conf->global->COWORK_ID

			),
		));

		$json = curl_exec($curl);
		return json_decode($json);
	}

	public function getTodayReservations(): array {
		global $conf;

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/place/reservations/today',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$this->user->token,
				'Coworkid: '.$conf->global->COWORK_ID
			),
		));

		$json = curl_exec($curl);

		curl_close($curl);

		return json_decode($json);
	}

    public function getCoworks()
    {
		global $conf;

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST.'/superadmin/places',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$this->user->token,
				'Coworkid: '.$conf->global->COWORK_ID

			),
		));

		$json = curl_exec($curl);

		curl_close($curl);

		return json_decode($json);
    }

	public function getContractsWithAmount(): array {
		global $conf;

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $conf->global->COWORK_API_HOST.'/admin/contracts/billing',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$this->user->token,
				'Coworkid: '.$conf->global->COWORK_ID
			),
		));

		$json = curl_exec($curl);

		curl_close($curl);

		return json_decode($json);
	}

}
