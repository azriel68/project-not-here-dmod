<?php

namespace Dolibarr\Cowork;

dol_include_once('/cowork/service/CoreService.php');

use Dolibarr\Core\CoreService;

class ThirdpartyService extends CoreService {

	/**
	 * @throws \Exception
	 */
	public function updateOrcreate(array $data, $entity): \Societe {

		$societe = new \Societe($this->db);

		$societe->fetch(0, '', '', '', '','', '', '', '','',$data['email']);
		$societe->ref_ext = $data['ref_ext'];
		$societe->name = $data['name'];
		$societe->client = 1;
		$societe->address = $data['address'];
		$societe->town = $data['city'];
		$societe->zip = $data['zip'];
		$societe->phone = $data['phone'];
		$societe->email = $data['email'];
                $societe->idprof1 = $data['company_siren'];
                $societe->tva_intra = $data['company_vat_code'];
                $societe->typent_id = dol_getIdFromCode($this->db, empty($data['company']) ? 'TE_PRIVATE' : 'TE_MEDIUM', 'c_typent', 'code', 'id');
                $societe->country_id = dol_getIdFromCode($this->db, empty($data['company_country_code']) ? 'FRA' : $data['company_country_code'], 'c_country', 'code_iso', 'rowid');
		$societe->entity = $entity;
                $societe->tva_assuj = !empty($data['company_vat_code']) && !empty($data['company']) ? 1 : 0;

		if ($societe->id > 0) {
			$res = $societe->update($societe->id, $this->user);
		}
		else {
			$res = $societe->create($this->user);
		}

		if ($res<0) {
			throw new \Exception($societe->error);
		}

		return $societe;
	}

}
