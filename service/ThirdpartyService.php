<?php

namespace Dolibarr\Cowork;

require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

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
		$societe->name = self::toUtf8mb3($data['name']);
		$societe->client = 1;
		$societe->address = self::toUtf8mb3($data['address']);
		$societe->town = self::toUtf8mb3($data['city']);
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

	/**
	 * Contact of the person behind the account when the thirdparty is a company
	 * (several people can share a company): looked up in the thirdparty contacts
	 * by first name and last name, created when missing.
	 *
	 * @return int Contact id, 0 when not a company or no name given
	 * @throws \Exception
	 */
	public function findOrCreateContact(\Societe $societe, array $data, $entity): int {

		$company   = trim((string)($data['company'] ?? ''));
		$firstname = trim((string)($data['firstname'] ?? ''));
		$lastname  = trim((string)($data['lastname'] ?? ''));

		if ($company === '' || ($firstname === '' && $lastname === '')) {
			return 0;
		}

		$sql = "SELECT sp.rowid FROM ".MAIN_DB_PREFIX."socpeople sp"
			." WHERE sp.fk_soc = ".((int)$societe->id)
			." AND LOWER(TRIM(COALESCE(sp.lastname, ''))) = '".$this->db->escape(dol_strtolower($lastname))."'"
			." AND LOWER(TRIM(COALESCE(sp.firstname, ''))) = '".$this->db->escape(dol_strtolower($firstname))."'"
			." ORDER BY sp.rowid ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			throw new \Exception('Contact::'.$this->db->lasterror());
		}
		if ($obj = $this->db->fetch_object($res)) {
			return (int)$obj->rowid;
		}

		$contact = new \Contact($this->db);
		$contact->socid     = $societe->id;
		$contact->entity    = $entity;
		$contact->firstname = $firstname;
		$contact->lastname  = $lastname;
		$contact->email     = $data['email'] ?? '';
		$contact->phone_pro = $data['phone'] ?? '';
		$contact->statut    = 1;

		if ($contact->create($this->user) < 0) {
			throw new \Exception('Contact::'.$contact->error);
		}

		return (int)$contact->id;
	}

}
