<?php

use Luracast\Restler\RestException;

dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

/**
 * API class for Cowork
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user}
 */
class Cowork extends DolibarrApi
{
    
    public function __construct() {
        
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
     * @url POST /entity
     *
     * @throws RestException
     */
    function defineEntity(): string
    {
        
        global $user, $db, $conf, $mysoc;

        $payload_string = @file_get_contents('php://input');
        $place = json_decode($payload_string);
        
        $sql = "SELECT value, entity FROM " . MAIN_DB_PREFIX . "const WHERE name='COWORK_ID' AND value='".$place->id."'";
        $result = $db->query($sql);

        $entityid = 0;
        if ($result) {
            $obj = $db->fetch_object($result);
            $entityid = $obj->entity;
        }

        $output = '';

        if ($entityid == 0) {
            $dao = new DaoMulticompany($db);
            $dao->label = $place->name;
            $dao->visible = 1;
            $dao->active = 1;

            $entityid = $dao->create($user);

            $output = 'Create entity ' . $dao->label . ' ' . $dao->id . "\n";

            $this->setConsts($db,[
                'COWORK_ID' => $place->id,
                'MAIN_LANG_DEFAULT' => 'fr_FR',
                'MAIN_MONNAIE' => 'EUR',
                'MAIN_MODULE_SETUP_ON_LIST_BY_DEFAULT' => 'commonkanban',
                'FACTURE_ADDON' => 'mod_facture_mars',
                'FACTURE_ADDON_PDF' => 'sponge',
                'SOCIETE_FISCAL_MONTH_START' => '1',
                'FACTURE_TVAOPTION' => '1',    
                'MAIN_INFO_SOCIETE_FORME_JURIDIQUE' => '',
            ],$dao->id);
        }

        $dao = new DaoMulticompany($db);
        $dao->fetch($entityid);
        $dao->label = $place->name;
        $dao->description = $place->id;        
        $dao->update($dao->id, $user);

        $this->setConsts($db,[
            'MAIN_INFO_SOCIETE_COUNTRY' => '1:FR:France',
            'MAIN_INFO_SOCIETE_STATE' => '',
            'MAIN_INFO_SOCIETE_NOM' => $place->invoice_companyName ?? $place->name,
            'MAIN_INFO_SOCIETE_ADDRESS' => $place->invoice_address ?? '',
            'MAIN_INFO_SOCIETE_TOWN' => $place->invoice_city ?? '',
            'MAIN_INFO_SOCIETE_ZIP' => $place->invoice_zip ?? '',
            'MAIN_INFO_SOCIETE_TEL' => $place->invoice_phone ?? '',
            'MAIN_INFO_SOCIETE_FAX' => $place->invoice_fax ?? '',
            'MAIN_INFO_SOCIETE_MAIL' => $place->invoice_email ?? '',
            'MAIN_INFO_SOCIETE_WEB' => $place->invoice_site ?? '',
            'MAIN_INFO_SIREN' => empty($place->invoice_siret) ? '' : substr($place->invoice_siret, 0, 9),
            'MAIN_INFO_SIRET' => $place->invoice_siret ?? '',
            'MAIN_INFO_TVAINTRA' => $place->invoice_vatCode ?? '',
            /*
            'MAIN_MODULE_SOCIETE' => '1',
            'MAIN_MODULE_FACTURE' => '1',
            'MAIN_MODULE_SERVICE' => '1',
            'MAIN_MODULE_BANQUE' => '1',*/
            
        ], $dao->id);  

        $output .= 'Update entity ' . $dao->label . ' ' . $dao->id . "\n";

        $sql = "SELECT count(*) AS nb FROM " . MAIN_DB_PREFIX . "const WHERE name='MAIN_MODULE_SOCIETE' AND entity='". $dao->id ."'";
        $result = $db->query($sql);

        $entityid = 0;
        if ($result) {
            $obj = $db->fetch_object($result);
            if ($obj->nb == 0) {

                $conf->entity = $dao->id;
                $conf->setValues($db);
                $mysoc->setMysoc($conf);
                activateModule('modFacture');
                activateModule('modService');
                activateModule('modBanque');

                $conf->entity = 1;
                $conf->setValues($db);
                $mysoc->setMysoc($conf);

            }
        }
        
        return 'ok';
    }
    
    private function setConsts($db,$data, $entityid) {
        foreach($data as $k=>$v) {
            dolibarr_set_const($db, $k, $v, 'chaine', 0, '', $entityid);

        }
    }
}
