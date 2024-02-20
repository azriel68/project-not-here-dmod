<?php

namespace Dolibarr\Cowork;
class ApiCoworkService {

    public ?\stdClass $user = null;

    public function __construct()
    {
    }
	private function getClient($url, $type = 'GET', $data = []): \CurlHandle {
		global $conf;

		$curl = curl_init();

		$headers = [
			'Content-Type: application/json',
			'Coworkid: '.$conf->global->COWORK_ID,
		];

		if (!empty($this->user->token)) {
			$headers[] = 'Authorization: Bearer '.$this->user->token;
		}

		curl_setopt_array($curl, array(
			CURLOPT_URL =>  $conf->global->COWORK_API_HOST . $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $type,
			CURLOPT_POSTFIELDS => json_encode($data),
			CURLOPT_HTTPHEADER => $headers,
		));

		return $curl;
	}
    public function fetchUser(): void {
        global $conf;

        $curl = $this->getClient( '/login', 'POST', [
			'email' => $conf->global->COWORK_API_USER,
			'password' => $conf->global->COWORK_API_PASSWORD,
		]);

        $user_string = curl_exec($curl);

        curl_close($curl);
        $this->user = json_decode($user_string);
    }

    public function getPaymentsPayed(): array {

        try {
			$curl = $this->getClient('/admin/payments/payed');
            $json = curl_exec($curl);

            curl_close($curl);

            return json_decode($json) ?? [];

        }
        catch(\Exception $exception) {
            dol_syslog(get_class($this).'::getBasketPayed '.$exception->getMessage());
            return [];
        }
    }

    public function setInvoiceRef($paymentId, $invoiceRef, $filepath): mixed {

		$curl = $this->getClient('/admin/payment/billed/'.$paymentId, 'POST', [
			'invoice_path'=>$filepath,
			'invoice_ref' =>$invoiceRef,
		]);

        $json = curl_exec($curl);
        return json_decode($json);
    }

    public function getTodayReservations(): array {
        global $conf;

		$curl = $this->getClient('/admin/place/reservations/today');

        $json = curl_exec($curl);

        curl_close($curl);

        return json_decode($json) ?? [];
    }

    public function getCoworks()
    {
        global $conf;

		$curl = $this->getClient('/superadmin/places');

        $json = curl_exec($curl);

        curl_close($curl);

        return json_decode($json);
    }

    public function getContractsWithAmount(): array {
        global $conf;

		$curl = $this->getClient('/admin/contracts/billing');

        $json = curl_exec($curl);

        curl_close($curl);

        return json_decode($json) ?? [];
    }

}
