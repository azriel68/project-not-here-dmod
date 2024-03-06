<?php

namespace Dolibarr\Cowork;

require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

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

		$payment->fk_account   = $this->getAccount()->id;

		if ($payment->create($this->user, 1, $invoice->thirdparty)<0) {
			throw new \Exception('Invoice Payment::'.$payment->error);
		}

		$payment->paiementcode = $payment->type_code;
		$payment->amounts = $payment->getAmountsArray();

		$payment->addPaymentToBank($this->user, 'payment', $invoice->ref,$payment->fk_account, '', '');

	}

	private function getAccount(): \Account {
		$account = new \Account($this->db);
		$ref = $conf->global->COWORK_ACCOUNT_REF ?? 'STRIPE';
		$account->fetch(0, $ref);
		if ($account->id <=0) {
			$account->ref = $ref;
			$account->label = 'DollyDesk Stripe';
			$account->country_id = dol_getIdFromCode($this->db, 'FR', 'c_country', 'code', 'rowid');
			$account->date_solde = dol_now();
			if ($account->create($this->user)<0) {
				throw new \Exception('Bank Account::'.$account->error);
			}
		}

		return $account;
	}
}
