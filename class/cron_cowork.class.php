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

		if (!class_exists('DaoMulticompany')) {
			$this->errors[] = 'Require DaoMulticompany';
			return -1;
		}

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
        global $db, $user, $conf;

        $mailService = \Dolibarr\Cowork\MailService::make($db, $user);

        $apiCoworkService = new \Dolibarr\Cowork\ApiCoworkService();

        $apiCoworkService->fetchUser();
        if (empty($apiCoworkService->user)) {
            $this->errors[] = 'login failed on '.$conf->global->COWORK_API_USER;
            return -9;
        }

        $data = $apiCoworkService->getPaymentsPayed();

        if (empty($data)) {
            $this->errors[] = 'No wallet found';
            return 0;
        }

        $this->output = '';

        try {
            foreach($data as $wallet) {
                $basket = $wallet->basket;
                $contract = $wallet->contract;
                $userData = $wallet->user;
                $placeData = $wallet->place;

                $entity = $this->getEntityToSwitch($placeData->id);
                if (null === $entity) {
                    $this->output .= ' (no managed) ';
                    continue; // not a managed entity
                }

                $body_details = [];
                $body = null;
                $invoice = null;

                if (!empty($basket)) {

                    $this->output.='basket '.$basket->id.PHP_EOL;
                    $invoice = $this->generateReservationInvoice($wallet, $body_details, $entity);

                    $title = "Votre réservation pour {$placeData->name} à été confirmée";

                    $files = [];
                    if (null!==$invoice) {
                        $files[] = new \Dolibarr\Cowork\MailFile(DOL_DATA_ROOT.'/'.$invoice->last_main_doc);
                        $body = $mailService->getWappedHTML('email.invoice.reservation', $title, [
                            'body_details' => implode("<br/>", $body_details),
                            'user' => $userData,
                        ]);
                    }
                    else {
                        $body = $mailService->getWappedHTML('email.reservation', $title,[
                            'body_details' => implode("<br/>", $body_details),
                            'user' => $userData,
                        ]);
                    }

                }
                else if (!empty($contract)) {
                    $this->output.='contract '.$contract->id.PHP_EOL;
                    $invoice = $this->generateContractInvoice($wallet, $body_details, $entity);

                    $title = "Votre facture de contrat pour {$placeData->name}";

                    $body = $mailService->getWappedHTML('email.invoice.contract', $title, [
                        'body_details' => implode("<br/>", $body_details),
                        'user' => $userData,
                    ]);


                    $files = [];
                    if (null!==$invoice) {
                        $files[] = new \Dolibarr\Cowork\MailFile(DOL_DATA_ROOT.'/'.$invoice->last_main_doc);
                    }
                }

                if (!empty($body)) {
                    $mailService->sendMail($title, $body, $placeData->name.' <'. $conf->global->MAIN_MAIL_EMAIL_FROM .'>', $userData->firstname.' '.$userData->lastname.' <'. $userData->email.'>', $files, true);
                }

                $apiCoworkService->setInvoiceRef($wallet->id, null===$invoice ? 'NO_INVOICE' : $invoice->ref, $invoice->last_main_doc ?? '');
            }

        }
        catch (Exception $exception) {
            $this->output.='Exception '.$exception->getMessage();
        }

        return 0;
    }

    private function initEntities(): void {
        global $db;

		if (!class_exists('DaoMulticompany')) {
			return;
		}

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

    private function getEntityToSwitch(string $coworkId): mixed
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

    private function generateReservationInvoice($wallet, &$body_details, $entity ): ?Facture
    {

        $basket = $wallet->basket;
        $userData = $wallet->user;

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

        $invoice = $this->generateInvoice($entity, [
            'ref_ext' => $wallet->id,
            'entity' => $entity->id,
            'thirdparty' => array_merge((array) $userData, [
                    'ref_ext' => $userData->email,
                    'name' => trim($userData->company) ? $userData->company : $userData->firstname. ' ' . $userData->lastname,
                ]
            ),
            'lines' => $lines,
            'payment_id' => substr( $wallet->paymentId, 0 , 30) ?? 'prepaid_contract',
        ]);

        return $invoice;
    }

    private function generateInvoice($entity, $data) {
        global $db, $user, $langs, $conf, $mysoc;

        $invoiceService = \Dolibarr\Cowork\InvoiceService::make($db, $user);
        $paymentService = \Dolibarr\Cowork\PaymentService::make($db, $user);

        $conf->entity = $entity->id;
        $conf->setValues($db);
        $mysoc->setMysoc($conf);

        $invoice = $invoiceService->create($data);
        $this->output .= ' invoice -> '. $invoice->ref;

        if ($invoice->getRemainToPay()>0 ) {
            $paymentService->createFromInvoice($invoice,  $data['payment_id']);
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

    private function generateContractInvoice($wallet, &$body_details, $entity ): ?Facture
    {
        global $langs;

        $contract = $wallet->contract;
        $userData = $wallet->user;

        $lines = [];
        $descriptions = [];

        $daysTrans = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

		$dateStart = new \DateTime($wallet->dateStart, new \DateTimeZone("UTC"));
		$dateEnd = new \DateTime($wallet->dateEnd, new \DateTimeZone("UTC"));

		foreach($contract->days as $day=>$deskId) {
            $desk = null;
            if (empty($desk) || $desk->id !== $deskId) {
                foreach($contract->desks as $desk) {
                    if ($desk->id === $deskId) {
                        break;
                    }
                }
            }
            $dayString = $langs->trans($daysTrans[(int)$day]);

//var_dump($desk, $dayString);exit;
            $descriptions[] = 'Salle '. $desk->room_name.($desk->reference ? ', bureau '.$desk->reference : '')
                .' le '.$dayString;

            $body_details[] = 'Le <strong>bureau '.$desk->reference.'</strong> dans la salle <strong>'. $desk->room_name.'</strong>
					 le '.$dayString;
        }

        foreach($contract->points as $k=>$nb) {
            if($nb > 0) {
                $descriptions[] = $nb. ' point(s) '. $langs->trans('coworkType'. $k);

                $body_details[] = $nb. ' point(s) '. $langs->trans('coworkType'. $k);

            }
        }



        $vat_rate = $wallet->place->vat_rate; //TODO contract vat_rate
        $lines[] = array_merge( (array)$contract, [
            'description' => implode(", ", $descriptions),
            'subprice' => $contract->amount,
            'tvatx' =>$vat_rate,
            'price' => $contract->amount * (1 + ($vat_rate/ 100)),
            'remise_percent' => $contract->discount_percent,
			'dateStart' => $dateStart->getTimestamp(),
			'dateEnd' => $dateEnd->getTimestamp(),

		]);

        if ($contract->amount === 0) {
            return null;
        }

        $invoice = $this->generateInvoice($entity, [
            'ref_ext' =>$wallet->id,
            'entity' => $entity->id,
            'thirdparty' => array_merge((array) $userData, [
                    'ref_ext' => $userData->email,
                    'name' => trim($userData->company) ? $userData->company : $userData->firstname. ' ' . $userData->lastname,
                ]
            ),
            'lines' => $lines,
            'payment_id' => substr( $wallet->paymentId,0,30) ?? 'prepaid_contract',
        ]);

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

            $title = " Rappel : Vous avez une réservation pour {$place->name} aujourd’hui !";

            $body=$mailService->getWappedHTML('email.reservation.today', $title, [
                    'reservation'=>$reservation,
                    'link_door'=>$place->front_url.'bookings',
                    'user' => $userData,
                    'hour_start' => $dateStart->format('H:i'),
                    'hour_end' => $dateEnd->format('H:i'),
                ]
            );

            $mailService->sendMail($title, $body, $place->name.' <'. $conf->global->MAIN_MAIL_EMAIL_FROM .'>', $userData->email, isHtml: true);
        }

        return 0;
    }

}
