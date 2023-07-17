<?php

namespace Dolibarr\Cowork;

require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
dol_include_once('/cowork/service/CoreService.php');

use Dolibarr\Core\CoreService;

class PaymentService extends CoreService {

	public function createFromInvoice(\Facture $invoice, string $paymentId): void {

		$payment = new \Paiement($this->db);
		$payment->datepaye     = $invoice->date;
		$payment->amounts      = [ $invoice->id => $invoice->total_ttc ]; // Array with all payments dispatching with invoice id
		$payment->paiementid   = dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
		$payment->num_payment  = $paymentId;
		$payment->note_private = 'stripe';
		$payment->fk_account   = 1; //TODO conf ?

		if ($payment->create($this->user, 1, $invoice->thirdparty)<0) {
			throw new \Exception('Invoice Paymet::'.$payment->error);
		}

	}

}
