<?php

dol_include_once('/cowork/service/InvoiceService.php');
dol_include_once('/cowork/service/PaymentService.php');
dol_include_once('/cowork/service/ApiCoworkService.php');
dol_include_once('/cowork/class/MailFile.class.php');
dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

class CronCowork {

    public array $errors = [];
    public string $error = '';
    private array $coworkEntities = [];
    private array $entities = [];
    
    /**
    *  Constructor
    *
    *  @param	DoliDb		$db      Database handler
    */
    public function __construct($db)
    {
           $this->db = $db;
    }

    public function createEntities(): int {
        global $conf, $user;

        if (!class_exists('DaoMulticompany')) {
            $this->errors[] = 'Require DaoMulticompany';
            return -1;
        }

        $apiCoworkService = new \Dolibarr\Cowork\ApiCoworkService();
        $apiCoworkService->fetchUser();
        if (empty($apiCoworkService->user)) {
            $this->errors[] = 'login failed on ' . $conf->global->COWORK_API_USER;
            return -9;
        }

        $data = $apiCoworkService->getCoworks();

        if (empty($data)) {
            $this->errors[] = 'No coworks found :-/';
            return 0;
        }

        $this->output = '';

        $this->initEntities();
        foreach ($data as $place) {
            if (!isset($this->coworkEntities[$place->id])) {
                $dao = new DaoMulticompany($this->db);
                $dao->label = $place->name;
                $dao->visible = 1;
                $dao->active = 1;

                $this->coworkEntities[$place->id] = $dao->create($user);

                $this->output .= 'Create entity ' . $dao->label . ' ' . $dao->id . "\n";

                dolibarr_set_const($this->db, 'COWORK_ID', $place->id, 'chaine', 0, '', $dao->id);
            }

            if (!empty($place->invoice_companyName)) { // update
                $dao = new DaoMulticompany($this->db);
                $dao->fetch($this->coworkEntities[$place->id]);
                $dao->name = $place->invoice_companyName;
                $dao->address = $place->invoice_address;
                $dao->zip = $place->invoice_zip;
                $dao->town = $place->invoice_city;
                $dao->update($dao->id, $user);
                dolibarr_set_const($this->db, 'MAIN_INFO_SOCIETE_TEL', $place->invoice_phone, 'chaine', 0, '', $dao->id);
                dolibarr_set_const($this->db, 'MAIN_INFO_SOCIETE_MAIL', $place->invoice_email, 'chaine', 0, '', $dao->id);
                dolibarr_set_const($this->db, 'MAIN_INFO_TVAINTRA', $place->invoice_vatCode, 'chaine', 0, '', $dao->id);
                dolibarr_set_const($this->db, 'MAIN_INFO_SIRET', $place->invoice_siret, 'invoice_siret', 0, '', $dao->id);
                dolibarr_set_const($this->db, 'MAIN_INFO_SIREN', substr($place->invoice_siret, 0, 9), 'invoice_siret', 0, '', $dao->id);
            }
        }


        return 0;
    }

    public function createSpotBills(): int {
        global $user, $conf;

        $apiCoworkService = new \Dolibarr\Cowork\ApiCoworkService();

        $apiCoworkService->fetchUser();
        if (empty($apiCoworkService->user)) {
            $this->errors[] = 'login failed on ' . $conf->global->COWORK_API_USER;
            return -9;
        }

        $data = $apiCoworkService->getPaymentsPayed();

        if (empty($data)) {
            $this->errors[] = 'No wallet found';
            return 0;
        }

        $this->output = '';

        $invoicesToSet = [];
        
        foreach ($data as $wallet) {
            try {
                $basket = $wallet->basket;
                $contract = $wallet->contract;
                $userData = $wallet->user;
                $placeData = $wallet->place;
                $entity = $this->getEntityToSwitch($placeData->id);
                if (null === $entity) {
                    $this->output .= ' (' . $placeData->id . ' no managed) ';
                    continue; // not a managed entity
                }

                $invoice = null;

                $invoice_path = null;
                
                if (!empty($basket)) {

                    $this->output .= 'basket ' . $basket->id . PHP_EOL;
                    $invoice = $this->generateReservationInvoice($wallet, $entity);
                    
                } else if (!empty($contract)) {
                    $this->output .= 'contract ' . $contract->id . PHP_EOL;
                    $invoice = $this->generateContractInvoice($wallet, $entity);
                }

                if (null !== $invoice) {
                    $invoice_path = DOL_DATA_ROOT . '/' . $invoice->last_main_doc;
                }
                
                $invoicesToSet[] = [$wallet->id, null === $invoice ? 'NO_INVOICE' : $invoice->ref, $invoice->last_main_doc ?? '', $invoice_path];
            } catch (Exception $exception) {
                var_dump('createSpotBills::Exception', $wallet->id, $wallet->place->id, $exception);
                $this->errors[] = 'Exception ' . $wallet->id . ' ' . $wallet->place->id . ' ' . $exception->getMessage();
                $this->output .= 'Exception ' . $wallet->id;
                
                return 0;
            }
        }

        foreach($invoicesToSet as $data) {
            $apiCoworkService->setInvoiceRef($data[0], $data[1], $data[2], $data[3]);
        }
        
        return 0;
    }

    private function initEntities(): void {
       
        if (!class_exists('DaoMulticompany')) {
            return;
        }

        $sql = "SELECT value, entity FROM " . MAIN_DB_PREFIX . "const WHERE name='COWORK_ID'";
        $result = $this->db->query($sql);

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $this->coworkEntities[$obj->value] = $obj->entity;
            }
        }

        $dao = new DaoMulticompany($this->db);
        $dao->getEntities();
        $this->entities = $dao->entities;
    }

    private function getEntityToSwitch(string $coworkId) {

        if (!class_exists('DaoMulticompany')) {
            $r = new stdClass();
            $r->id = 1;
            return $r;
        }

        if (empty($this->coworkEntities)) {
            $this->initEntities();
        }

        $result = null;

        foreach ($this->entities as $entity) {
            if (isset($this->coworkEntities[$coworkId]) && $entity->active && $entity->id == $this->coworkEntities[$coworkId]) {
                $result = $entity;
                break;
            }
        }

        return $result;
    }

    private function generateReservationInvoice($wallet, $entity): ?Facture {

        $basket = $wallet->basket;
        $userData = $wallet->user;

        $lines = [];

        $total = 0;
        foreach ($basket->reservations as $reservation) {
            $dateStart = new \DateTime($reservation->dateStart, new \DateTimeZone("UTC"));
            $dateEnd = new \DateTime($reservation->dateEnd, new \DateTimeZone("UTC"));

            $description = 'Salle ' . $reservation->roomName . ($reservation->deskReference ? ', bureau ' . $reservation->deskReference : '')
                    . ' le ' . $dateStart->format('d/m/Y') . ' de ' . $dateStart->format('H:i') . ' à ' . $dateEnd->format('H:i');
            $lines[] = array_merge((array) $reservation, [
                'description' => $description,
                'dateStart' => $dateStart->getTimestamp(),
                'dateEnd' => $dateEnd->getTimestamp(),
                'subprice' => $reservation->price,
                'tvatx' => $basket->vatRate,
                'price' => $reservation->price * (1 + ($basket->vatRate / 100)),
            ]);

            $total += $reservation->price;

        }

        return $this->getInvoice($total, $entity->id, $wallet, $userData, $lines);
    }

    private function generateInvoice(int $entity, $data) {
        global $user, $langs, $conf, $mysoc;

        $invoiceService = \Dolibarr\Cowork\InvoiceService::make($this->db, $user);
        $paymentService = \Dolibarr\Cowork\PaymentService::make($this->db, $user);

        $conf->entity = $entity;
        $conf->setValues($this->db);
        $mysoc->setMysoc($conf);

        $invoice = $invoiceService->create($data);
        $this->output .= ' invoice -> ' . $invoice->ref;

        if ($invoice->getRemainToPay() > 0) {
            $paymentService->createFromInvoice($invoice, $data['payment_id']);
        } else {
            $invoice->setPaid($user);
        }

        if ($invoice->generateDocument('sponge', $langs) < 0) {
            throw new \Exception('Invoice PDF::' . $invoice->error);
        }

        $conf->entity = 1;
        $conf->setValues($this->db);
        $mysoc->setMysoc($conf);

        return $invoice;
    }

    private function generateContractInvoice($wallet, $entity): ?Facture {
        global $langs;

        $contract = $wallet->contract;
        $userData = $wallet->user;

        $lines = [];
        $descriptions = [];

        $daysTrans = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $dateStart = new \DateTime($wallet->dateStart, new \DateTimeZone("UTC"));
        $dateEnd = new \DateTime($wallet->dateEnd, new \DateTimeZone("UTC"));

        foreach ($contract->days as $day => $deskId) {
            $desk = null;
            if (empty($desk) || $desk->id !== $deskId) {
                foreach ($contract->desks as $desk) {
                    if ($desk->id === $deskId) {
                        break;
                    }
                }
            }
            $dayString = $langs->trans($daysTrans[(int) $day]);
            $descriptions[] = 'Salle ' . $desk->room_name . ($desk->reference ? ', bureau ' . $desk->reference : '')
                    . ' le ' . $dayString;
        }

        foreach ($contract->points as $k => $nb) {
            if ($nb > 0) {
                $descriptions[] = $nb . ' point(s) ' . $langs->trans('coworkType' . $k);
            }
        }

        $vat_rate = $wallet->place->vat_rate; //TODO contract vat_rate
        $lines[] = array_merge((array) $contract, [
            'description' => implode(", ", $descriptions),
            'subprice' => $contract->amount,
            'tvatx' => $vat_rate,
            'price' => $contract->amount,
            'remise_percent' => $contract->discount_percent,
            'dateStart' => $dateStart->getTimestamp(),
            'dateEnd' => $dateEnd->getTimestamp(),
        ]);

        if(!empty($contract->products)) {
            foreach($contract->products as $cp) {
                $lines[] = array_merge((array) $cp, [
                    'description' => $cp->product->name,
                    'subprice' => $cp->product->price,
                    'tvatx' => $vat_rate,
                    'quantity' => $cp->quantity,
                    'price' => $cp->product->price * $cp->quantity * ( 1 + ($vat_rate / 100) ),
                    'remise_percent' => 0,
                    'dateStart' => $dateStart->getTimestamp(),
                    'dateEnd' => $dateEnd->getTimestamp(),
                ]);
            }
        }
        
        return $this->getInvoice($wallet->amount, $entity->id, $wallet, $userData, $lines);
    }

    /**
     * @param float $amount
     * @param int $entity
     * @param stdclass $wallet
     * @param stdclass $userData
     * @param array $lines
     * @return Facture|null
     * @throws Exception
     */
    private function getInvoice(float $amount, int $entity, \stdclass $wallet, \stdclass $userData, array $lines): ?Facture {
        if ($amount === 0.0) {
            return null;
        }

        $invoice = $this->generateInvoice($entity, [
            'ref_ext' => $wallet->id,
            'entity' => $entity,
            'thirdparty' => array_merge((array) $userData, [
                'ref_ext' => $userData->email,
                'name' => trim($userData->company) ? $userData->company : $userData->firstname . ' ' . $userData->lastname,
                    ]
            ),
            'lines' => $lines,
            'payment_id' => substr($wallet->paymentId, 0, 30) ?? 'prepaid_contract',
        ]);

        return $invoice;
    }
}
