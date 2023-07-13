<?php

use Luracast\Restler\RestException;

/**
 * API class for Worwork
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user}
 */
class Cowork extends DolibarrApi {

	public function __construct()
	{

	}

	/**
	 * @param int    $userBasketId  basket's id to pay
	 * @return string link
	 *
	 * @url     GET basket/{userBasketId}/payment
	 *
	 * @throws RestException
	 */
	function createPaymentForReservations(int $userBasketId): string {
		global $user;



		return 'bla'. $user->id;
	}
}
