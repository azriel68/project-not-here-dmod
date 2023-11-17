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

		try {
		foreach($data as $wallet) {
			$basket = $wallet->basket;

			$this->output.='basket '.$basket->id.PHP_EOL;

			$userData = & $wallet->user;

			$placeData = &$userData->place;

			$body_details = [];

			$invoice = $this->generateInvoice($basket, $userData, $body_details);

			$title = "Votre réservation pour le '{$placeData->name}' à été confirmée";

			$body = "<html><body>
<p>Merci d’avoir reservé :<br /><br />
".implode('<br />', $body_details)."</p>

<p>{$userData->firstname} {$userData->lastname} <br />
{$userData->email} <br />
{$userData->phone} <br />
</p>
";
			$files = [];
			if (null!==$invoice) {
				$body.="<br />
<p>
Veuillez trouver ci-joint la facture de votre/vos réservation(s)<br /></p><br />
";

				$files[] = new \Dolibarr\Cowork\MailFile(substr($conf->facture->multidir_output[$invoice->entity], 0,-8).'/'.$invoice->last_main_doc);
			}

			$body.="
			<p>
<br />
À bientôt 👋<br /></p>
</body></html>";

			$mailService->sendMail($title, $body, $mysoc->name.' <'. $mysoc->email.'>', $userData->firstname.' '.$userData->lastname.' <'. $userData->email.'>', $files, true);


			$apiCoworkService->setInvoiceRef($basket->id, null==$invoice ? 'NO_INVOICE' : $invoice->ref);
		}

		}
		catch (Exception $exception) {
			$this->output.='Exception '.$exception->getMessage();
		}

		return 0;
	}

	private function generateInvoice(&$basket, &$userData, &$body_details): ?Facture
	{

		global $db, $user, $langs, $conf;

		$invoiceService = \Dolibarr\Cowork\InvoiceService::make($db, $user);
		$paymentService = \Dolibarr\Cowork\PaymentService::make($db, $user);

		$lines = [];

		$total = 0;
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

			$total+=$pr->amount;

			$body_details[] = "Le <strong>bureau ".$pr->deskReference."</strong> dans la salle '<strong>". $pr->roomName."</strong>'
					 le ".$dateStart->format('d/m/Y')." de ".$dateStart->format('H:i')." à ".$dateEnd->format('H:i');
		}

		if ($total === 0) {
			return null;
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


		if ($invoice->total_ht>0) {
			$paymentService->createFromInvoice($invoice, $basket->paymentId ?? 'prepaid_contract');
		}
		else {
			$invoice->setPaid($user);
		}

		if ($invoice->generateDocument('sponge', $langs) < 0) {
			throw new \Exception('Invoice PDF::'.$invoice->error);
		}

		return $invoice;
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

			$title = " Rappel : Vous avez une réservation au '{$mysoc->name}' aujourd’hui !";

			$body="<html><body>
<p>Bonjour,<br />
<br />
Vous avez réservé :<br />
<br />
Le <strong>bureau {$reservation->deskReference}</strong> dans la salle '<strong>{$reservation->roomName}</strong>'<br />
aujourd’hui de ".$dateStart->format('H:i')." à ".$dateEnd->format('H:i')."<br />
</p>
<br />
<p>{$userData->firstname} {$userData->lastname} <br />
{$userData->email} <br />
{$userData->phone} <br />
</p>
<br />
<p>Si besoin, pour ouvrir la porte,<br />
<br />
<a href=\"{$conf->global->COWORK_FRONT_URI}/bookings\" style=\"background:linear-gradient(to bottom, #e8b8a5 5%, #e8b8a5 100%);	background-color:#e8b8a5;	border-radius:28px;	border:1px solid #e8b8a5; font-weight: bold;	display:inline-block;	cursor:pointer;	color:#4a8198;	font-family:Arial;	font-size:17px;	padding:16px 31px;	text-decoration:none;	text-shadow:0px 1px 0px #e8b8a5;\"> cliquez ici ></a><br />
<br />
À tout à l'heure 👋<br />
</p>
</body></html>";

			$mailService->sendMail($title, $body, $mysoc->name.' <'. $mysoc->email.'>', $userData->email, isHtml: true);
		}

		return 0;
	}

}
