<?php

namespace Dolibarr\Core;

class CoreService {
	public function __construct(protected readonly \DoliDB $db, protected  readonly \User $user)
	{
	}

	static function make(\DoliDB $db, \User $user): static {
		return new static($db, $user);
	}

}
