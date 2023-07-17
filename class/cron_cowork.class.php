<?php

dol_include_once('/cowork/service/InvoiceService.php');
dol_include_once('/cowork/service/PaymentService.php');
dol_include_once('/cowork/service/MailService.php');
dol_include_once('/cowork/class/MailFile.class.php');

class CronCowork {

	public function createSpotBills() {
		global $db, $user, $langs, $conf, $mysoc;

		$invoiceService = \Dolibarr\Cowork\InvoiceService::make($db, $user);
		$paymentService = \Dolibarr\Cowork\PaymentService::make($db, $user);
		$mailService = \Dolibarr\Cowork\MailService::make($db, $user);

		//TODO service ApiCoworkService
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
		$user_api = json_decode($user_string);

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
				'Authorization: Bearer '.$user_api->token
			),
		));

		$json = curl_exec($curl);

		curl_close($curl);

		$data = json_decode($json);
		foreach($data as $wallet) {
			$basket = $wallet->basket;

			$this->output.='basket '.$basket->id.PHP_EOL;

			$userData = & $wallet->user;

			$lines = [];
			foreach($basket->pendingReservations as $pr) {
				$dateStart = new \DateTime($pr->dateStart, new \DateTimeZone("UTC"));
				$dateEnd = new \DateTime($pr->dateEnd, new \DateTimeZone("UTC"));

				$lines[] = array_merge( (array)$pr, [
					'description' => 'Salle '. $pr->roomName.($pr->deskReference ? ', bureau '.$pr->deskReference : '')
						.' du '.$dateStart->format('d/m/Y H:i').' à '.$dateEnd->format('H:i'),
					'dateStart' => $dateStart->getTimestamp(),
					'dateEnd' => $dateEnd->getTimestamp(),
					'subprice' => $pr->amount,
					'tvatx' => $basket->vatRate,
					'price' => $pr->amountTTC,
				]);

			}

			$invoice = $invoiceService->create([
				'ref_ext' => 'basket-'.$basket->id,
				'entity' => $conf->entity,
				'thirdparty' => array_merge((array) $userData, [
						'ref_ext' => $userData->email,
						'name' => trim($userData->company) ? $userData->company : $userData->firstname. ' ' . $userData->lastname,
					]
				),
				'lines' => $lines,
			]);

			$paymentService->createFromInvoice($invoice, $basket->paymentId);

			if ($invoice->generateDocument('sponge', $langs) < 0) {
				throw new \Exception('Invoice PDF::'.$invoice->error);
			}

			$fileMailInvoice = new \Dolibarr\Cowork\MailFile(substr($conf->facture->multidir_output[$invoice->entity], 0,-7).'/'.$invoice->last_main_doc);
			$mailService->sendMail('Facture '.$mysoc->name, 'ci-joint votre facture', $mysoc->email, $userData->email);


		}


	}

}
