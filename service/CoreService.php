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

}
