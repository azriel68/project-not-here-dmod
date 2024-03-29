<?php

use Dolibarr\Cowork\MailService;
use Luracast\Restler\RestException;

/**
 * API class for Cowork
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
     * @url GET /invoice/download/{entity}/{ref}
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
     * @return string
     *
     * @url POST /reservation/cancel/mail
     *
     * @throws RestException
     */
    function cancelEmail(): string
    {
        global $conf, $db, $user;

        $payload_string = @file_get_contents('php://input');
        $reservation = json_decode($payload_string);
        if (null !== $reservation) {
            dol_include_once('/cowork/service/MailService.php');

            $placeData = $reservation->place;
            $userData = $reservation->user;

            $mailService = MailService::make($db, $user);

            $title = "Votre réservation pour {$placeData->name} à été annulée";

            $dateStart = new \DateTime($reservation->dateStart->date, new \DateTimeZone("UTC"));
            $dateEnd = new \DateTime($reservation->dateEnd->date, new \DateTimeZone("UTC"));

            $body = $mailService->getWappedHTML('email.reservation.cancel', $title, [
                    'reservation' => $reservation,
                    'user' => $userData,
                    'date' => $dateStart->format('d/m/Y'),
                    'hour_start' => $dateStart->format('H:i'),
                    'hour_end' => $dateEnd->format('H:i'),
                ]
            );

            $mailService->sendMail($title, $body, $placeData->name.' <' . $conf->global->MAIN_MAIL_EMAIL_FROM . '>', $userData->firstname . ' ' . $userData->lastname . ' <' . $userData->email . '>', $placeData->emails_cci ?? '', [], true);


            return 'ok';
        }


        throw new RestException(403, 'Invalid call');

    }

    /**
     * @return string
     *
     * @url POST /account/lost
     *
     * @throws RestException
     */
    function lostAccountEmail(): string
    {
        global $conf, $db, $user;

        $payload_string = @file_get_contents('php://input');
        $payload = json_decode($payload_string);
        if (null !== $payload) {
            dol_include_once('/cowork/service/MailService.php');

            $placeData = $payload->place;
            $userData = $payload->user;

            $mailService = MailService::make($db, $user);

            $title = "Votre accès {$placeData->name}";

            $body = $mailService->getWappedHTML('email.account.lost', $title, [
                    'user' => $userData,
                    'link' => $payload->link
                ]
            );

            $mailService->sendMail($title, $body, $placeData->name.' <' . $conf->global->MAIN_MAIL_EMAIL_FROM . '>', $userData->firstname . ' ' . $userData->lastname . ' <' . $userData->email . '>', $placeData->emails_cci ?? '', [], true);

            return 'ok';
        }


        throw new RestException(403, 'Invalid call');

    }

    /**
     * @return string
     *
     * @url POST /contract/new/mail
     *
     * @throws RestException
     */
    function contractEmail(): string
    {
        global $conf, $db, $user;

        $payload_string = @file_get_contents('php://input');
        $payload = json_decode($payload_string);
        if (null !== $payload) {
            dol_include_once('/cowork/service/MailService.php');

            $contractData = $payload->contract;
            $placeData = $contractData->place;
            $userData = $contractData->user;

            $mailService = MailService::make($db, $user);

            $title = "Votre contrat pour {$placeData->name}";

            $body = $mailService->getWappedHTML('email.contract', $title, [
                    'contract' => $contractData,
                    'user' => $userData,
                    'place' => $placeData,
                    'link_payment' => $payload->link_payment
                ]
            );

            $mailService->sendMail($title, $body, $placeData->name.' <' . $conf->global->MAIN_MAIL_EMAIL_FROM . '>', $userData->firstname . ' ' . $userData->lastname . ' <' . $userData->email . '>', $placeData->emails_cci ?? '', [], true);

            return 'ok';
        }


        throw new RestException(403, 'Invalid call');

    }

    /**
     * @return string
     *
     * @url POST /contract/error/mail
     *
     * @throws RestException
     */
    function contractErrorEmail(): string
    {
        global $conf, $db, $user;

        $payload_string = @file_get_contents('php://input');
        $payload = json_decode($payload_string);
        if (null !== $payload) {
            dol_include_once('/cowork/service/MailService.php');

            $contractData = $payload->contract;
            $placeData = $contractData->place;
            $userData = $contractData->user;

            $mailService = MailService::make($db, $user);

            $title = "Une erreur s'est produite sur votre contrat {$placeData->name}";

            $body = $mailService->getWappedHTML('email.invoice.contract.error', $title, [
                    'contract' => $contractData,
                    'link_payment' => $payload->link_payment
                ]
            );

            $mailService->sendMail($title, $body, $placeData->name.' <' . $conf->global->MAIN_MAIL_EMAIL_FROM . '>', $userData->firstname . ' ' . $userData->lastname . ' <' . $userData->email . '>', $placeData->emails_cci ?? '', [], true);

            return 'ok';
        }


        throw new RestException(403, 'Invalid call');

    }

    /**
     * @url GET /test/mail/{template}
     *
     * @param string $template
     * @return string
     *
     * @throws RestException
     */
    function testMail(string $template): string
    {
        global $conf, $db, $user;
        dol_include_once('/cowork/service/MailService.php');
        $mailService = MailService::make($db, $user);

        $body = $mailService->getWappedHTML('email.' . $template, 'Ceci est mon titre', [
                'contract' => [
                    'discounted_amount' => 99
                ],
                'place' => [
                    'name' => 'cowork',
                ],
                'link_payment' => '#',
                'user' => [
                    'firstname' => 'Prénom',
                    'lastname' => 'Nom',
                    'email' => 'me@yoh.fr',
                    'phone' => '+33 6 66 66 66 66',
                ]
            ]
        );

        exit($body);

    }

	/**
	 * @url POST /test/mail/{template}
	 *
	 * @param string $template
	 * @return string
	 *
	 * @throws RestException
	 */
	function testSendMail(string $template): string
	{
		global $conf, $db, $user;
		dol_include_once('/cowork/service/MailService.php');
		$mailService = MailService::make($db, $user);
		$subject = 'Ceci est mon titre';
		$body = $mailService->getWappedHTML('email.' . $template, $subject, [
				'contract' => [
					'discounted_amount' => 99
				],
				'place' => [
					'name' => 'cowork',
				],
				'link_payment' => '#',
				'user' => [
					'firstname' => 'Prénom',
					'lastname' => 'Nom',
					'email' => 'me@yoh.fr',
					'phone' => '+33 6 66 66 66 66',
				]
			]
		);

		$mailService->sendMail($subject, $body,'DollyDesk Test <'. $conf->global->MAIN_MAIL_EMAIL_FROM .'>', $user->firstname.' '.$user->lastname.' <'. $user->email.'>' );

		return 1;
	}
}
