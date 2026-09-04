<?php
/* $Id:$ */

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

global $db;
$version = FreePBX::Config()->get('ASTVERSION');
global $sqlTables;
$sqlTables = array('sccpbuttonconfig','sccpdevice','sccpline','sccpuser','sccpsettings','sccpdevmodel');
createBackUpConfig();

function createBackUpConfig()
{
    global $amp_conf;
    global $sqlTables;
    outn("<li>" . _("Creating Config BackUp") . "</li>");
    $cnf_int = \FreePBX::Config();
    $backup_files = array('extensions','extconfig','res_mysql', 'res_config_mysql','sccp','sccp_hardware','sccp_extensions');
    $backup_ext = array('_custom.conf', '_additional.conf','.conf');
    $dir = $cnf_int->get('ASTETCDIR');


    $sqlTables = array('sccpbuttonconfig','sccpdevice','sccpline','sccpuser','sccpsettings','sccpdevmodel');
    $sqlBuFile = $dir.'/sccp_backup_'.date("Ymd").'.sql';

    // Credentials go in a defaults-extra-file (0600, removed right after) instead of on the
    // command line, where they'd be readable via `ps` and end up in the shell error output.
    $credFile = tempnam(sys_get_temp_dir(), 'sccpdump_');
    file_put_contents($credFile, "[client]\nuser={$amp_conf['AMPDBUSER']}\npassword={$amp_conf['AMPDBPASS']}\n");
    chmod($credFile, 0600);

    $tablesEsc = implode(' ', array_map('escapeshellarg', $sqlTables));
    $cmd = "mysqldump --defaults-extra-file=" . escapeshellarg($credFile)
         . " --single-transaction " . escapeshellarg($amp_conf['AMPDBNAME']) . " {$tablesEsc}"
         . " > " . escapeshellarg($sqlBuFile) . " 2>" . escapeshellarg($sqlBuFile . '.err');
    exec($cmd, $output, $return_var);
    unlink($credFile);

    $dumpOk = ($return_var === 0) && file_exists($sqlBuFile) && filesize($sqlBuFile) > 0;
    if (!$dumpOk) {
        outn("<li><font color='red'>" . _("Error creating database backup - mysqldump failed:") . "</font></li>");
        outn("<pre>" . htmlspecialchars(@file_get_contents($sqlBuFile . '.err')) . "</pre>");
        @unlink($sqlBuFile);
        @unlink($sqlBuFile . '.err');
        die_freepbx();
    }
    @unlink($sqlBuFile . '.err');

    try {
        $zip = new \ZipArchive();
    } catch (\Exception $e) {
        outn("<br>");
        outn("<font color='red'>PHPx.x-zip not installed where x.x is the installed PHP version. Install it before continuing !</font>");
        die_freepbx();
    }
    $filename = $dir . "/sccp_uninstall_backup" . date("Ymd"). ".zip";
    if ($zip->open($filename, \ZIPARCHIVE::CREATE)) {
        foreach ($backup_files as $file) {
            foreach ($backup_ext as $b_ext) {
                if (file_exists($dir . '/'.$file . $b_ext)) {
                    $zip->addFile($dir . '/'.$file . $b_ext);
                }
            }
        }
        if (file_exists($sqlBuFile)) {
            $zip->addFile($sqlBuFile);
        }
        $zip->close();
    } else {
        outn("<li>" . _("Error Creating BackUp: ") . $filename ."</li>");
        outn("<br>");
        outn("<font color='red'>PHPx.x-zip not installed where x.x is the installed PHP version. Install it before continuing !</font>");
        die_freepbx();
    }
    unlink($sqlBuFile);
    outn("<li>" . _("Config backup created: ") . $filename ."</li>");
}

if (!empty($version)) {
    $check = $db->getRow("SELECT 1 FROM `kvstore` LIMIT 0", DB_FETCHMODE_ASSOC);
    if (!(DB::IsError($check))) {
        outn("<li>" . _("Deleting keys FROM kvstore..") . "</li>");
        sql("DELETE FROM kvstore WHERE module = 'sccpsettings'");
        sql("DELETE FROM kvstore WHERE module = 'Sccp_manager'");
    }
  }
  // FreePbx removes all tables via module.xml, and uninstaller will error
  // If the tables do not exist.
  //outn("<li>" . _('Removing all Sccp_manager tables') . "</li>");
  //foreach ($sqlTables as $table) {
      //$sql = "DROP TABLE IF EXISTS {$table}";
  //}
  // Still need to handle views as FreePBX does not know about these.
  outn("<li>" . _('Removing all Sccp_manager views') . "</li>");
  // Both views the installer creates (see install.php) must be dropped;
  // sccplineconfig was previously left behind.
  $db->query("DROP VIEW IF EXISTS sccpdeviceconfig");
  $db->query("DROP VIEW IF EXISTS sccplineconfig");

  outn("<li>" . _("Uninstall Complete") . "</li>");
?>
