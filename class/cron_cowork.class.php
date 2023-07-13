<?php

dol_include_once('/cowork/service/InvoiceService.php');

class CronCowork {

	public function createSpotBills() {
		global $db, $user, $langs;

		$invoiceService = \Dolibarr\Cowork\InvoiceService::make($db, $user);

		$json = '[{"id":10, "user": {
			"firstname": "Alexis",
			"lastname": "Algoud",
			"company": "",
			"address": "241 ch. des sablières",
			"zip": "07240",
			"city": "Vernoux-en-vivarais",
			"email": "alexisalgoud@yahoo.fr",
			"phone":"0673736250"
		}, "pendingReservations":[{"id":21,"dateStart":"2023-07-27T09:00:00+00:00","dateEnd":"2023-07-27T18:00:00+00:00","createdAt":"2023-07-13T14:20:53+00:00","updatedAt":"2023-07-13T14:20:53+00:00","hourStart":"09:00","hourEnd":"18:00","deskReference":"03","amount":99,"amountTTC":118.8,"roomName":"Patron"}],"vatRate":20,"expiresAt":"2023-07-13T14:58:32+00:00","status":"PAYED","total":99,"totalTTC":118.8,"paymentId":"pi_3NTQT6AMUARqwUDv3DIJCfnI"}]';

		$data = json_decode($json);
		foreach($data as $basket) {
			$this->output.='basket '.$basket->id.PHP_EOL;

			$userData = & $basket->user;

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
					'tvatx' => (($pr->amountTTC - $pr->amount) / $pr->amount) * 100,
					'price' => $pr->amountTTC,
				]);
			}

			$invoice = $invoiceService->create([
				'ref_ext' => 'basket-'.$basket->id,
				'thirdparty' => array_merge((array) $basket->user, [
						'ref_ext' => $userData->email,
						'name' => trim($userData->company) ? $userData->company : $userData->firstname. ' ' . $userData->lastname,
					]
				),
				'lines' => $lines,
			]);

			if ($invoice->generateDocument('sponge', $langs) < 0) {
				throw new \Exception('Invoice PDF::'.$invoice->error);
			}


		}


	}

}
