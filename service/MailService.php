<?php

namespace Dolibarr\Cowork;

dol_include_once('/cowork/service/CoreService.php');

use Dolibarr\Core\CoreService;

class MailService extends CoreService {

	/**
	 * @param string $subject
	 * @param string $from
	 * @param string $to
	 * @param string $message
	 * @param array<MailFile> $files
	 * @return bool|string
	 */
	public function sendMail(string $subject, string $message, string $from, string $to, array $files = []): bool
	{

		$filepath = $mimetype = $filename = [];
		foreach($files as $file) {
			$filepath[] = $file->path;
			$mimetype[] = $file->mimetype;
			$filename[] = $file->name;
		}

//var_dump($subject, $to, $from, $message, $filepath, $mimetype, $filename);exit;
@		$mailFile = new \CMailFile($subject, $to, $from, $message, $filepath, $mimetype, $filename, trackid: md5($to.$subject.$message));

		if (false === $mailFile->sendfile()) {
			throw new \Exception('Unable to send mail ['.$subject.'] to '.$to.' from '.$from);
		}

		return true;
	}
}
