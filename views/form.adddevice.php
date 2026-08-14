<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
$def_val = array();
$dev_id = null;
$device_warning= null;
// Default value from Server setings
//Get default values. Will use these for a new device, and modify for an existing.
$def_val = $this->getTableDefaults('sccpdevice');
$def_val['netlang'] =  array("keyword" => 'netlang', "data" => $this->sccpvalues['netlang']['data'] ?? '', "seq" => "99");
$def_val['devlang'] =  array("keyword" => 'devlang', "data" => $this->sccpvalues['devlang']['data'] ?? '', "seq" => "99");
$def_val['directed_pickup_context'] =  array("keyword" => 'directed_pickup_context', "data" => $this->sccpvalues['directed_pickup_context']['data'] ?? '', "seq" => "99");

if (!empty($_REQUEST['new_id'])) {
    // Adding device that is connected but not in database
    $dev_id = $_REQUEST['new_id'];
    // Overwrite some specific defaults based on $_REQUEST
    $def_val['type'] = array("keyword" => 'type', "data" => $_REQUEST['type'], "seq" => "99");
    if (!empty($_REQUEST['addon'])) {
        $def_val['addon'] = array("keyword" => 'addon', "data" => $_REQUEST['addon'], "seq" => "99");
    }
}

if (!empty($_REQUEST['id'])) {
    // Editing an existing Device. Overwrite any defaults that are already set for this device.
    $dev_id = $_REQUEST['id'];

    $db_res = $this->dbinterface->getSccpDeviceTableData('get_sccpdevice_byid', array("id" => $dev_id));
    foreach ($db_res as $key => $val) {
        if (!empty($val)) {
            switch ($key) {
                case 'phonepersonalization':
                    $def_val['phonepersonalization'] =  array("keyword" => 'phonepersonalization', "data" => $val, "seq" => "99");
                    break;
                default:
                    // Overwrite existing defaults after checking that data is still valid after schema updates
                    // Do not strip underscores as these fields are new in the schema and so should be valid.
                    $enumFields = $this->getTableEnums('sccpdevice', false);
                    if (array_key_exists($key, $enumFields)){
                        // This field is (now) an enum. Check the current value is acceptable.
                        // Quote value as enum values are quoted.
                        if (in_array("'{$val}'", $enumFields[$key])) {
                            // The value is valid so will keep
                            $def_val[$key] = array('keyword' => $key, 'data' => $val, 'seq' => 99);
                        }
                        // Do not store value and let defaults apply
                        break;
                    }
                    $def_val[$key] = array("keyword" => $key, "data" => $val, "seq" => "99");
                    // Need to assign defaultLine as not set in the db.
                    $def_val['defaultLine'] = $this->dbinterface->getSccpDeviceTableData('getDefaultLine', array('id' => $dev_id))['name'];
                    break;
            }
        }
    }
}

if (!empty($def_val['type']['data'])) {
    $tmp_raw = $this->getSccpModelInformation('byid', true, 'all', array('model'=>$def_val['type']['data']));
    if (!empty($tmp_raw[0])) {
        $tmp_raw = $tmp_raw[0];
    }
    if (!empty($tmp_raw['validate'])) {
        // validate is "yes"/"no;"" per field, or "-" when the field isn't defined for
        // this model at all (e.g. no loadimage registered) - only "no" is a real problem.
        $tmpar =  explode(";", $tmp_raw['validate']);
        if ($tmpar[0] === 'no') {
            $device_warning['Image'] = array('Device firmware not found : '.$tmp_raw['loadimage']);
        }
        if ($tmpar[1] === 'no') {
            $device_warning['Template'] = array('Missing device configuration template : '. $tmp_raw['nametemplate']);
        }
        // $device_warning (if set) is picked up by Sccp_manager::processPageData() and
        // rendered in the shared page-level banner slot (page.html.php, above the tab
        // strip), matching the warning banners on the other tabs - not rendered here.
    }
} ?>

<form autocomplete="off" name="frm_adddevice" id="frm_adddevice" class="fpbx-submit" action="" method="post" data-id="hw_edit">
    <input type="hidden" name="category" value="adddevice_form">
    <input type="hidden" name="Submit" value="Submit">

    <?php

    if (empty($dev_id)) {
        echo '<input type="hidden" name="sccp_deviceid" value="new">';
    } else {
        $val = str_replace(array('SEP','ATA','VG'), '', $dev_id);
        $val = implode(':', sscanf($val, '%2s%2s%2s%2s%2s%2s')); // Convert to Cisco display Format
        $def_val['mac'] = array("keyword" => 'mac', "data" => $val, "seq" => "99");
        echo '<input type="hidden" name="sccp_device_id" value="'.$this->escapeHtml($dev_id).'">';
    }

    if ($_REQUEST['tech_hardware'] == 'cisco') {
        echo '<input type="hidden" name="sccp_device_typeid" value="sccpdevice">';
        if (empty($dev_id)) {
            echo $this->showGroup('sccp_hw_dev', 1, 'sccp_hw', $def_val);
        } else {
            echo $this->showGroup('sccp_hw_dev_edit', 1, 'sccp_hw', $def_val);
        }
        echo $this->showGroup('sccp_hw_dev2', 1, 'sccp_hw', $def_val);
        echo $this->showGroup('sccp_hw_dev_advance', 1, 'sccp_hw', $def_val);
        echo $this->showGroup('sccp_hw_dev_softkey', 1, 'sccp_hw', $def_val);
        // echo $this->showGroup('sccp_hw_dev_pickup', 1, 'sccp_hw', $def_val); This are line properties and does not exist!
        echo $this->showGroup('sccp_hw_dev_conference', 1, 'sccp_hw', $def_val);
        echo $this->showGroup('sccp_dev_vendor_conf', 1, 'vendorconfig', $def_val);
        echo $this->showGroup('sccp_hw_dev_network', 1, 'sccp_hw', $def_val);

    } else if ($_REQUEST['tech_hardware'] == 'cisco-sip') {
        echo '<input type="hidden" name="sccp_device_typeid" value="sipdevice">';
        /*
        if (empty($dev_new)) {
            echo '<input type="hidden" name="sccp_deviceid" value="new">';
        } else {
            echo '<input type="hidden" name="sccp_deviceid" value="'.$dev_id.'">';
        }
        */
        if (empty($dev_id)) {
            echo $this->showGroup('sccp_hw_sip_dev', 1, 'sccp_hw', $def_val);
        } else {
            echo $this->showGroup('sccp_hw_dev_edit', 1, 'sccp_hw', $def_val);
        }
        echo $this->showGroup('sccp_hw_sip_dev2', 1, 'sccp_hw', $def_val);
        echo $this->showGroup('sccp_hw_sip_conf', 1, 'sccp_hw', $def_val);
    }
    ?>
</form>
