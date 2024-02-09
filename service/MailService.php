<?php

namespace Dolibarr\Cowork;

dol_include_once('/cowork/service/CoreService.php');
require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
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
	public function sendMail(string $subject, string $message, string $from, string $to, array $files = [], $isHtml = false): bool
	{

		$filepath = $mimetype = $filename = [];
		foreach($files as $file) {
			$filepath[] = $file->path;
			$mimetype[] = $file->mimetype;
			$filename[] = $file->name;
		}

//var_dump($subject, $to, $from, $message, $filepath, $mimetype, $filename);exit;
@		$mailFile = new \CMailFile($subject, $to, $from, $message, $filepath, $mimetype, $filename, msgishtml: (int)$isHtml, trackid: md5($to.$subject.$message), replyto: $from);

		if (false === $mailFile->sendfile()) {
			throw new \Exception('Unable to send mail ['.$subject.'] to '.$to.' from '.$from);
		}

		return true;
	}

	private function getReplacements(mixed $params, string $prepend = '') :array
	{
		$result = [];
		foreach($params as $key => $value) {
			if (is_array($value) || is_object($value)) {
				$result = array_merge($result, $this->getReplacements($value, $key.'_'));
			}
			else {
				$result['__'.$prepend.$key.'__'] = $value;
			}
		}

		return array_change_key_case($result, CASE_UPPER);
	}

    public function getHTML(string $file, array $params): string
    {
		$replacements = $this->getReplacements($params);
//		var_dump($replacements);
		$html = file_get_contents(__DIR__.'/../templates/'.$file.'.html');

		$html = strtr($html, $replacements);

		return $html;
    }
}
