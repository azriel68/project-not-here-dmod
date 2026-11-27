<?php

namespace Dolibarr\Cowork;

require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

dol_include_once('/cowork/service/CoreService.php');

use Dolibarr\Core\CoreService;

class ThirdpartyService extends CoreService {

	/**
	 * Thirdparty of the account, looked up in this order:
	 * - company with a valid SIREN: the thirdparty of the entity with this SIREN
	 *   (several people of a company share one thirdparty, each one is a contact of it)
	 * - else the thirdparty with the account email
	 * - else a new thirdparty
	 *
	 * @throws \Exception
	 */
	public function updateOrcreate(array $data, $entity): \Societe {

		$societe = new \Societe($this->db);

		$siren = empty(trim((string)($data['company'] ?? ''))) ? '' : self::normalizeSiren($data['company_siren'] ?? '');

		$socidBySiren = $siren !== '' ? $this->findIdBySiren($siren, $entity) : 0;
		if ($socidBySiren > 0) {
			if ($societe->fetch($socidBySiren) <= 0) {
				throw new \Exception('Thirdparty::'.$societe->error);
			}
		}
		else {
			$societe->fetch(0, '', '', '', '','', '', '', '','',$data['email']);
		}

		// Shared company thirdparty: keep its own email, phone and ref_ext (the person is a contact of it)
		if ($socidBySiren <= 0) {
			$societe->ref_ext = $data['ref_ext'];
			$societe->phone = $data['phone'];
			$societe->email = $data['email'];
		}

		$societe->name = self::toUtf8mb3($data['name']);
		$societe->client = 1;
		$societe->address = self::toUtf8mb3($data['address']);
		$societe->town = self::toUtf8mb3($data['city']);
		$societe->zip = $data['zip'];
                $societe->idprof1 = $siren !== '' ? $siren : $data['company_siren'];
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
	 * SIREN (9 digits, Luhn key checked) from a user input that may be a SIREN or
	 * a SIRET, with spaces, dots...
	 *
	 * @return string SIREN, '' when the input is not a valid SIREN or SIRET
	 */
	public static function normalizeSiren(?string $value): string {

		$digits = preg_replace('/\D/', '', (string)$value);
		if (strlen($digits) !== 9 && strlen($digits) !== 14) {
			return '';
		}

		$siren = substr($digits, 0, 9);

		$sum = 0;
		for ($i = 0; $i < 9; $i++) {
			$digit = (int)$siren[$i];
			if ($i % 2 === 1) { // every second digit from the right of 9 digits
				$digit *= 2;
				if ($digit > 9) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}

		return $sum % 10 === 0 ? $siren : '';
	}

	/**
	 * Oldest thirdparty of the entity whose SIREN (idprof1, may be stored as a SIRET
	 * or with spaces) is the given one.
	 *
	 * @return int Thirdparty id, 0 when none
	 * @throws \Exception
	 */
	private function findIdBySiren(string $siren, $entity): int {

		$sql = "SELECT s.rowid, s.idprof1 FROM ".MAIN_DB_PREFIX."societe s"
			." WHERE s.entity = ".((int)$entity)
			." AND s.idprof1 IS NOT NULL AND s.idprof1 <> ''"
			." ORDER BY s.rowid ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			throw new \Exception('Thirdparty::'.$this->db->lasterror());
		}

		// Compare normalized values: the stored SIREN comes from a free user input
		while ($obj = $this->db->fetch_object($res)) {
			if (self::normalizeSiren($obj->idprof1) === $siren) {
				return (int)$obj->rowid;
			}
		}

		return 0;
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
