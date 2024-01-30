<?php

dol_include_once('/cowork/service/InvoiceService.php');
dol_include_once('/cowork/service/PaymentService.php');
dol_include_once('/cowork/service/MailService.php');
dol_include_once('/cowork/service/ApiCoworkService.php');
dol_include_once('/cowork/class/MailFile.class.php');
dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

class CronCowork {

	public array $errors = [];
	public string $error = '';

	private array $coworkEntities = [];
	private array $entities = [];

	public function createEntities() : int
	{
		global $conf, $db, $user;

		$apiCoworkService = new \Dolibarr\Cowork\ApiCoworkService();
		$apiCoworkService->fetchUser();
		if (empty($apiCoworkService->user)) {
			$this->errors[] = 'login failed on '.$conf->global->COWORK_API_USER;
			return -9;
		}

		$data = $apiCoworkService->getCoworks();

		if (empty($data)) {
			$this->errors[] = 'No coworks found :-/';
			return 0;
		}

		$this->output = '';

		$this->initEntities();
		foreach($data as $place) {
			if (!isset($this->coworkEntities[$place->id])) {
				$dao = new DaoMulticompany($db);
				$dao->label = $place->name;
				$dao->visible = 1;
				$dao->active = 1;
				$dao->create($user);

				$this->output .= 'Create entity '.$dao->label.' '.$dao->id."\n";

				dolibarr_set_const($db, 'COWORK_ID', $place->id, entity: $dao->id);
			}
		}


		return 0;
	}

	public function createSpotBills(): int
	{
		global $db, $user, $langs, $conf;

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


			$placeData = &$basket->place;
			$entity = $this->getEntityToSwitch($placeData->id);
			if (null === $entity) {
				$this->output .= ' (no managed) ';
				continue; // not a managed entity
			}

			$body_details = [];

			$invoice = $this->generateInvoice($basket, $userData, $body_details, $entity);

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

				$files[] = new \Dolibarr\Cowork\MailFile(DOL_DATA_ROOT.'/'.$invoice->last_main_doc);
			}

			$body.="
			<p>
<br />
À bientôt 👋<br /></p>
</body></html>";

			$mailService->sendMail($title, $body, $placeData->name.' <'. $conf->global->MAIN_MAIL_EMAIL_FROM .'>', $userData->firstname.' '.$userData->lastname.' <'. $userData->email.'>', $files, true);


			$apiCoworkService->setInvoiceRef($basket->id, null==$invoice ? 'NO_INVOICE' : $invoice->ref, $invoice->last_main_doc ?? '');
		}

		}
		catch (Exception $exception) {
			$this->output.='Exception '.$exception->getMessage();
		}

		return 0;
	}

	private function initEntities(): void {
		global $db;
		$sql = "SELECT value, entity FROM ".MAIN_DB_PREFIX."const WHERE name='COWORK_ID'";
		$result = $db->query($sql);

		if ($result) {
			while($obj = $db->fetch_object($result)) {
				$this->coworkEntities[$obj->value] = $obj->entity;

			}

		}

		$dao = new DaoMulticompany($db);
		$dao->getEntities();
		$this->entities = $dao->entities;

	}

	private function getEntityToSwitch(string $coworkId): null|DaoMulticompany|stdClass
	{

		if (!class_exists('DaoMulticompany')) {
			$r = new stdClass();
			$r->id = 1;
			return $r;
		}


		if (empty($this->coworkEntities)) {
			$this->initEntities();
		}

		$result  = null;

		foreach($this->entities as $entity) {
			if (isset($this->coworkEntities[$coworkId]) && $entity->active && $entity->id == $this->coworkEntities[$coworkId] ) {
				$result = $entity;
				break;
			}
		}

		return $result;
	}

	private function generateInvoice(&$basket, &$userData, &$body_details, $entity ): ?Facture
	{

		global $db, $user, $langs, $conf, $mysoc;

		$invoiceService = \Dolibarr\Cowork\InvoiceService::make($db, $user);
		$paymentService = \Dolibarr\Cowork\PaymentService::make($db, $user);

		$lines = [];

		$total = 0;
		foreach($basket->reservations as $reservation) {
			$dateStart = new \DateTime($reservation->dateStart, new \DateTimeZone("UTC"));
			$dateEnd = new \DateTime($reservation->dateEnd, new \DateTimeZone("UTC"));

			$description = 'Salle '. $reservation->roomName.($reservation->deskReference ? ', bureau '.$reservation->deskReference : '')
				.' du '.$dateStart->format('d/m/Y H:i').' à '.$dateEnd->format('H:i');
			$lines[] = array_merge( (array)$reservation, [
				'description' => $description,
				'dateStart' => $dateStart->getTimestamp(),
				'dateEnd' => $dateEnd->getTimestamp(),
				'subprice' => $reservation->price,
				'tvatx' => $basket->vatRate,
				'price' => $reservation->price * (1 + ($basket->vatRate / 100)),
			]);

			$total+=$reservation->price;

			$body_details[] = "Le <strong>bureau ".$reservation->deskReference."</strong> dans la salle '<strong>". $reservation->roomName."</strong>'
					 le ".$dateStart->format('d/m/Y')." de ".$dateStart->format('H:i')." à ".$dateEnd->format('H:i');
		}

		if ($total === 0) {
			return null;
		}

		$conf->entity = $entity->id;
		$conf->setValues($db);
		$mysoc->setMysoc($conf);

		$invoice = $invoiceService->create([
			'ref_ext' => 'basket-'.$basket->id,
			'entity' => $entity->id,
			'thirdparty' => array_merge((array) $userData, [
					'ref_ext' => $userData->email,
					'name' => trim($userData->company) ? $userData->company : $userData->firstname. ' ' . $userData->lastname,
				]
			),
			'lines' => $lines,
		]);
		echo $invoice->ref;

		if ($invoice->total_ht>0) {
			$paymentService->createFromInvoice($invoice, $basket->paymentId ?? 'prepaid_contract');
		}
		else {
			$invoice->setPaid($user);
		}

		if ($invoice->generateDocument('sponge', $langs) < 0) {
			throw new \Exception('Invoice PDF::'.$invoice->error);
		}

		$conf->entity = 1;
		$conf->setValues($db);
		$mysoc->setMysoc($conf);

		return $invoice;
	}

	function reminderForTodayReservations(): int {
		global $db, $user, $langs, $conf;

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
			$place = $reservation->place;

			$entity = $this->getEntityToSwitch($place->id);
			if (null === $entity) {
				continue; // not a managed entity
			}


			$dateStart = new \DateTime($reservation->dateStart, new \DateTimeZone("UTC"));
			$dateEnd = new \DateTime($reservation->dateEnd, new \DateTimeZone("UTC"));

			$title = " Rappel : Vous avez une réservation au '{$place->name}' aujourd’hui !";

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
<a href=\"{$place->front_url}bookings\" style=\"background:linear-gradient(to bottom, #FA7C71 5%, #FA7C71 100%);	background-color:#FA7C71;	border-radius:28px;	border:1px solid #FA7C71; font-weight: bold;	display:inline-block;	cursor:pointer;	color:#001E24;	font-family:Arial;	font-size:17px;	padding:16px 31px;	text-decoration:none;	text-shadow:0px 1px 0px #FA7C71;\"> cliquez ici </a><br />
<br />
À tout à l'heure 👋<br />
</p>
</body></html>";

			$mailService->sendMail($title, $body, $place->name.' <'. $conf->global->MAIN_MAIL_EMAIL_FROM .'>', $userData->email, isHtml: true);
		}

		return 0;
	}

}
