<?php

dol_include_once('/cowork/service/InvoiceService.php');
dol_include_once('/cowork/service/PaymentService.php');
dol_include_once('/cowork/service/MailService.php');
dol_include_once('/cowork/service/ApiCoworkService.php');
dol_include_once('/cowork/class/MailFile.class.php');

class CronCowork {

	public array $errors = [];
	public string $error = '';

	public function createSpotBills(): int
	{
		global $db, $user, $langs, $conf, $mysoc;

		$invoiceService = \Dolibarr\Cowork\InvoiceService::make($db, $user);
		$paymentService = \Dolibarr\Cowork\PaymentService::make($db, $user);
		$mailService = \Dolibarr\Cowork\MailService::make($db, $user);

		$apiCoworkService = new \Dolibarr\Cowork\ApiCoworkService();

		$apiCoworkService->fetchUser();
		if (empty($apiCoworkService->user)) {
			$this->errors[] = 'login failed on '.$conf->global->COWORK_API_USER;
			return -9;
		}

		$data = $apiCoworkService->getBasketPayed();

		if (empty($data)) {
			$this->errors[] = 'No wallet found';
			return 0;
		}

		$this->output = '';

		foreach($data as $wallet) {
			$basket = $wallet->basket;

			$this->output.='basket '.$basket->id.PHP_EOL;

			$userData = & $wallet->user;

			$body_details = "";

			$lines = [];
			foreach($basket->pendingReservations as $pr) {
				$dateStart = new \DateTime($pr->dateStart, new \DateTimeZone("UTC"));
				$dateEnd = new \DateTime($pr->dateEnd, new \DateTimeZone("UTC"));

				$description = 'Salle '. $pr->roomName.($pr->deskReference ? ', bureau '.$pr->deskReference : '')
					.' du '.$dateStart->format('d/m/Y H:i').' à '.$dateEnd->format('H:i');
				$lines[] = array_merge( (array)$pr, [
					'description' => $description,
					'dateStart' => $dateStart->getTimestamp(),
					'dateEnd' => $dateEnd->getTimestamp(),
					'subprice' => $pr->amount,
					'tvatx' => $basket->vatRate,
					'price' => $pr->amountTTC,
				]);

				$body_details.= $description." \n";
			}

			$invoiceRef = 'NO_LINE';
			if (!empty($lines)) {

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

				$invoiceRef = $invoice->ref;

				/* ?
				$rootfordata = DOL_DATA_ROOT;
				if (isModEnabled('multicompany') && !empty($this->entity) && $this->entity > 1) {
					$rootfordata .= '/'.$this->entity;
				}
				 */

				$title = "Votre réservation à '{$mysoc->name}' à été confirmée";

				$body = "{$title} \n
{$mysoc->name} \n
Prénom: {$userData->firstname} \n
Nom: {$userData->lastname} \n
Adresse email: {$userData->email} \n
Numéro de téléphone: {$userData->phone} \n
\n
{$body_details}
\n
Veuillez trouver ci-joint la facture de votre/vos réservation(s)
				";

				$mailService->sendMail($title, $body, $mysoc->name.' <'. $mysoc->email.'>', $userData->firstname.' '.$userData->lastname.' <'. $userData->email.'>', [
					new \Dolibarr\Cowork\MailFile(substr($conf->facture->multidir_output[$invoice->entity], 0,-8).'/'.$invoice->last_main_doc)
				]);

			}

			$apiCoworkService->setInvoiceRef($basket->id, $invoiceRef);

		}

		return 0;
	}

	function reminderForTodayReservations(): int {
		global $db, $user, $langs, $conf, $mysoc;

		$mailService = \Dolibarr\Cowork\MailService::make($db, $user);

		$apiCoworkService = new \Dolibarr\Cowork\ApiCoworkService();

		$apiCoworkService->fetchUser();
		if (empty($apiCoworkService->user)) {
			$this->errors[] = 'login failed on '.$conf->global->COWORK_API_USER;
			return -9;
		}

		$reservations = $apiCoworkService->getTodayReservations();

		if (empty($reservations)) {
			$this->errors[] = 'No reservation found';
			return 0;
		}

		foreach($reservations as $reservation) {

			$this->output.='reservation '.$reservation->id.PHP_EOL;

			$userData = & $reservation->user;

			$dateStart = new \DateTime($reservation->dateStart, new \DateTimeZone("UTC"));
			$dateEnd = new \DateTime($reservation->dateEnd, new \DateTimeZone("UTC"));

			$title = "Rappel : Votre réservation à '{$mysoc->name}' du ".$dateStart->format('d/m/Y H:i').' à '.$dateEnd->format('H:i')."";

			$body = "{$title} \n
Prénom: {$userData->firstname} \n
Nom: {$userData->lastname} \n
Adresse email: {$userData->email} \n
Numéro de téléphone: {$userData->phone} \n
\n
Salle ".$reservation->roomName.($reservation->deskReference ? ', bureau '.$reservation->deskReference : '')."
	du ".$dateStart->format('d/m/Y H:i').' à '.$dateEnd->format('H:i')."
\n
Si besoin, pour ouvrir la porte, cliquez sur ce lien ".$conf->global->COWORK_FRONT_URI."/bookings
				";

			$mailService->sendMail($title, $body, $mysoc->email, $userData->email);
		}

		return 0;
	}

}
