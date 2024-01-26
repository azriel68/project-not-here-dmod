<?php

use Luracast\Restler\RestException;

/**
 * API class for Worwork
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user}
 */
class Cowork extends DolibarrApi
{

	public function __construct()
	{

	}

	/**
	 * @param int $userBasketId basket's id to pay
	 * @return string link
	 *
	 * @url     GET basket/{userBasketId}/payment
	 *
	 * @throws RestException
	 */
	function createPaymentForReservations(int $userBasketId): string
	{
		global $user;


		return 'bla' . $user->id;
	}

	/**
	 * @param int $entity basket's id to pay
	 * @return string link
	 *
	 * @url     GET /invoice/download/{entity}/{ref}
	 *
	 * @throws RestException
	 */
	function downloadInvoice(int $entity, string $ref): array
	{
		global $conf, $db;

		if ($entity > 1) {
			$conf->entity = $entity;
			$conf->setValues($db);
		}

		$original_file = $conf->facture->multidir_output[$conf->entity] . '/' . $ref . '/' . $ref . '.pdf';

		$filename = basename($original_file);
		$original_file_osencoded = dol_osencode($original_file); // New file name encoded in OS encoding charset

		if (!file_exists($original_file_osencoded)) {
			throw new RestException(404, 'File not found');
		}

		$file_content = file_get_contents($original_file_osencoded);
		return [
			'filename' => $filename,
			'content-type' => dol_mimetype($filename),
			'filesize' => filesize($original_file),
			'content' => base64_encode($file_content),
			'encoding' => 'base64'
		];
	}

	/**
	 * @return bool
	 *
	 * @url     POST /reservation/cancel/mail
	 *
	 * @throws RestException
	 */
	function cancelEmail(): bool
	{
		global $conf, $db, $user;

		$payload_string = @file_get_contents('php://input');
		$reservation = json_decode($payload_string);
		if (null !== $reservation) {
			dol_include_once('/cowork/service/MailService.php');

//			'id' => $this->reservation->getId(),
//            'price' => $this->reservation->getPrice(),
//            'points' => $this->reservation->getPoints(),
//            'dateStart' => $this->reservation->getDateStart(),
//            'dateEnd' => $this->reservation->getDateEnd(),
//            'roomName' => $this->reservation->getRoomName(),
//            'deskReference' => $this->reservation->getDeskReference(),
//            'user' => new UserPresenter($this->reservation->getBasket()->getUser()),
//            'place' => new CoworkPresenter($this->reservation->getPlace())

			$placeData = $reservation->place;
			$userData = $reservation->user;

			$mailService = \Dolibarr\Cowork\MailService::make($db, $user);

			$title = "Votre réservation pour le '{$placeData->name}' à été annulée";

			$body = "<html><body>
<p>Votre réservation a été correctement annulée, {$reservation->points} point(s) vous ont été recrédités <br /><br />
";

			$dateStart = new \DateTime($reservation->dateStart->date, new \DateTimeZone("UTC"));
			$dateEnd = new \DateTime($reservation->dateEnd->date, new \DateTimeZone("UTC"));

			$body.="Le <strong>bureau ".$reservation->deskReference."</strong> dans la salle '<strong>". $reservation->roomName."</strong>'
					 le ".$dateStart->format('d/m/Y')." de ".$dateStart->format('H:i')." à ".$dateEnd->format('H:i');


			$body.= "</p>
				<p>{$userData->firstname} {$userData->lastname} <br />
				{$userData->email} <br />
				{$userData->phone} <br />
				</p>
				<p>
				<br />
				À bientôt 👋<br /></p>
				</body></html>";

			$mailService->sendMail($title, $body, $placeData->name.' <'. $conf->global->MAIN_MAIL_EMAIL_FROM .'>', $userData->firstname.' '.$userData->lastname.' <'. $userData->email.'>', [], true);


			return true;
		}


		throw new RestException(403, 'Invalid call');

	}
}
