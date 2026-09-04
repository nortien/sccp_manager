<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

?>
<form autocomplete="off" name="frm_device" id="frm_device" class="fpbx-submit" action="" method="post">
    <input type="hidden" name="category" value="deviceform">
    <input type="hidden" name="Submit" value="Submit">
    <?php
    // Warning banner for this tab is rendered by page.html.php, above the
    // tab strip (see the "banner" key set in Sccp_manager::settingsShowPage()).
    ?>
<?php

        $def_val_line = $this->getTableDefaults('sccpline');
        $def_val_device = $this->getTableDefaults('sccpdevice');

        //echo $this->showGroup('sccp_dev_config', 1, 'sccpdevice', $def_val_device);
        echo $this->showGroup('sccp_dev_config', 1);
        echo $this->showGroup('sccp_dev_group_config', 1);
        echo $this->showGroup('sccp_dev_advconfig', 1);
        echo $this->showGroup('sccp_dev_softkey', 1);
        echo $this->showGroup('sccp_hotline_config', 1);
        echo $this->showGroup('sccp_qos_config', 1, 'sccpdevice', $def_val_device);
?>
</form>
