<?php

namespace Dolibarr\Cowork;

dol_include_once('/cowork/service/CoreService.php');

use Dolibarr\Core\CoreService;

class ThirdpartyService extends CoreService {

	/**
	 * @throws \Exception
	 */
	public function updateOrcreate(array $data): \Societe {

		$societe = new \Societe($this->db);

		$societe->fetch( rowid: 0, email: $data['email']);
		$societe->ref_ext = $data['ref_ext'];
		$societe->name = $data['name'];
		$societe->client = 1;
		$societe->address = $data['address'];
		$societe->town = $data['city'];
		$societe->zip = $data['zip'];
		$societe->phone = $data['phone'];
		$societe->email = $data['email'];

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
