<?php

namespace Dolibarr\Core;

class CoreService {

	protected \DoliDB $db;
	protected \User $user;

	public function __construct(\DoliDB $db, \User $user)
	{
		$this->db  =$db;
		$this->user  =$user;
	}

	static function make(\DoliDB $db, \User $user) {
		return new static($db, $user);
	}

	/**
	 * Dolibarr tables are utf8mb3: 4-byte characters (emojis...) make the INSERT fail.
	 * Strip them from any text coming from cowork before storing it.
	 */
	public static function toUtf8mb3(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		$clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value);
		if ($clean === null) { // invalid UTF-8
			return $value;
		}

		return trim(preg_replace('/ {2,}/', ' ', $clean));
	}

}
