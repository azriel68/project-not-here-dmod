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
}
