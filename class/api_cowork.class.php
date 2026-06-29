<?php

use Luracast\Restler\RestException;

dol_include_once('/multicompany/class/actions_multicompany.class.php', 'ActionsMulticompany');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

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

        $ref = base64_decode($ref);
        
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
     * Export invoices of an entity for a given period as a ZIP (transactions.csv + PDFs)
     *
     * @param string $coworkId  COWORK_ID guid (stored in llx_const per entity)
     * @param string $dateStart Start date YYYY-MM-DD (inclusive)
     * @param string $dateEnd   End date   YYYY-MM-DD (inclusive)
     * @return array  { filename, content-type, filesize, content (base64), encoding }
     *
     * @url GET /invoice/export/{coworkId}/{dateStart}/{dateEnd}
     *
     * @throws RestException
     */
    function exportInvoices(string $coworkId, string $dateStart, string $dateEnd): array
    {
        global $conf, $db;
 
        // Resolve entity from COWORK_ID constant
        $sql    = "SELECT entity FROM " . MAIN_DB_PREFIX . "const"
                . " WHERE name='COWORK_ID' AND value='" . $db->escape($coworkId) . "'";
        $resEnt = $db->query($sql);
        if (!$resEnt || !($objEnt = $db->fetch_object($resEnt))) {
            throw new RestException(404, 'No entity found for COWORK_ID: ' . $coworkId);
        }
        $entity = (int)$objEnt->entity;
 
        // Switch entity
        if ($entity > 1) {
            $conf->entity = $entity;
            $conf->setValues($db);
        }
 
        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
            throw new RestException(400, 'Dates must be YYYY-MM-DD');
        }
 
        // Fetch entity label
        $sqlLbl      = "SELECT label FROM " . MAIN_DB_PREFIX . "entity WHERE rowid = " . $entity;
        $resLbl      = $db->query($sqlLbl);
        $entityLabel = ($resLbl && ($objLbl = $db->fetch_object($resLbl))) ? $objLbl->label : (string)$entity;
 
        // Fetch invoices for the period
        $sql  = "SELECT f.rowid, f.ref, f.datef, f.date_lim_reglement,";
        $sql .= " f.total_ht, f.total_ttc, f.total_tva, f.paye,";
        $sql .= " s.nom AS tiers, s.code_client AS code, c.code AS pays, s.tva_intra";
        $sql .= " FROM "   . MAIN_DB_PREFIX . "facture f";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_country c ON c.rowid = s.fk_pays";
        $sql .= " WHERE f.entity = " . $entity;
        $sql .= " AND f.datef BETWEEN '" . $db->escape($dateStart) . "' AND '" . $db->escape($dateEnd)   . "'";
        $sql .= " AND f.fk_statut IN (".Facture::STATUS_VALIDATED.",".Facture::STATUS_CLOSED.")";
        $sql .= " ORDER BY f.datef ASC, f.ref ASC";
 
        $result = $db->query($sql);
        if (!$result) {
            throw new RestException(500, 'DB error: ' . $db->lasterror());
        }
 
        // CSV header — same columns as the reference export
        $csvColumns = [
            'Type', 'Environnement', 'Date', 'Date échéance', 'Réf.',
            'Total HT', 'Total TTC', 'Total TVA',
            'Total Taxe 2', 'Total Taxe 3', 'Timbre fiscal',
            'Payé', 'Document', 'ItemID', 'Tiers', 'Code',
            'Pays', 'Numéro de TVA', 'Sens'
        ];
 
        $csvLines   = [];
        $csvLines[] = implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $csvColumns));
        $invoiceRefs = [];
 
        while ($obj = $db->fetch_object($result)) {
            $date    = dol_print_date($db->jdate($obj->datef),                '%Y-%m-%d');
            $dateEch = dol_print_date($db->jdate($obj->date_lim_reglement),   '%Y-%m-%d');
 
            $invoiceRefs[$obj->ref] = true;
 
            $row = [
                'Facture',
                $entityLabel,
                $date,
                $dateEch,
                $obj->ref,
                number_format((float)$obj->total_ht,  8, '.', ''),
                number_format((float)$obj->total_ttc, 8, '.', ''),
                number_format((float)$obj->total_tva, 8, '.', ''),
                number_format(0,  8, '.', ''),
                number_format(0,  8, '.', ''),
                number_format(0,  8, '.', ''),
                $obj->paye ? '1' : '0',
                $obj->ref . '.pdf',
                $obj->rowid,
                $obj->tiers   ?? '',
                $obj->code    ?? '',
                $obj->pays    ?? '',
                $obj->tva_intra ?? '',
                '1'
            ];
 
            $csvLines[] = implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row));
        }
 
        $csvContent = implode("\n", $csvLines);
 
        // Build ZIP
        $zipFile = tempnam(sys_get_temp_dir(), 'cowork_export_') . '.zip';
        $zip     = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RestException(500, 'Cannot create ZIP archive');
        }
 
        $zip->addFromString('transactions.csv', $csvContent);
 
        $invoiceDir = $conf->facture->multidir_output[$conf->entity] ?? '';
        foreach ($invoiceRefs as $ref => $_) {
            $pdfPath   = $invoiceDir . '/' . $ref . '/' . $ref . '.pdf';
            $pdfPathOS = dol_osencode($pdfPath);
            if (file_exists($pdfPathOS)) {
                $zip->addFile($pdfPathOS, 'invoices/' . $ref . '.pdf');
            }
        }
 
        $zip->close();
 
        $zipContent = file_get_contents($zipFile);
        unlink($zipFile);
 
        $exportName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_',
            'export_' . $entityLabel . '_' . $dateStart . '_' . $dateEnd . '.zip');
 
        return [
            'filename'     => $exportName,
            'content-type' => 'application/zip',
            'filesize'     => strlen($zipContent),
            'content'      => base64_encode($zipContent),
            'encoding'     => 'base64'
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
