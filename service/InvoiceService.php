<?php

namespace Dolibarr\Cowork;

require_once DOL_DOCUMENT_ROOT.'/core/class/notify.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

dol_include_once('/cowork/service/CoreService.php');
dol_include_once('/cowork/service/ThirdpartyService.php');
dol_include_once('/cowork/service/PaymentService.php');

use Dolibarr\Core\CoreService;

class InvoiceService extends CoreService {

    public function create(array $data): \Facture
    {
        global $conf;
        $this->db->begin();

		$invoice = new \Facture($this->db);

		$invoice->fetch(0, '', $data['ref_ext']); //for obscur reason (and surely a middle bug, the invoice already exist)
		if ($invoice->id > 0) {
			return $invoice;
		}

		$invoice->ref_ext = $data['ref_ext']; //TODO check if ref_ext already exist
        $invoice->entity = $data['entity'];
        $invoice->type = \Facture::TYPE_STANDARD;
        $invoice->brouillon = 0;
        $invoice->status = $invoice->statut = \Facture::STATUS_DRAFT;
        $invoice->date = dol_now();

        $thirdpartyService = ThirdpartyService::make($this->db, $this->user);

        $invoice->socid = ($thirdpartyService->updateOrcreate($data['thirdparty'], $invoice->entity))->id;

        if ($invoice->create($this->user)<0) {
            throw new \Exception($invoice->error);
        }

        $this->addLines($invoice, $data['lines']);

        if ($invoice->validate($this->user)<0) {
            throw new \Exception('Invoice Validatation::'.$invoice->error);
        }

        $this->db->commit();

        return $invoice;
    }

    private function addLines(\Facture $invoice, array $lines): void {
        foreach($lines as $line) {
            $res = $invoice->addline(
                $line['description'],
                $line['subprice'],
                1,
                $line['tvatx'],
                0,
                0,
                0,
                $line['remise_percent'] ?? 0,
                $line['dateStart'] ?? '',
                $line['dateEnd'] ?? '',
                0,
                0,
                0,
                'HT',
                $line['price'],
                \Product::TYPE_SERVICE,
            );

            if ($res<0) {
                throw new \Exception('Invoice AddLine::'.$invoice->error);
            }

        }
    }


}
