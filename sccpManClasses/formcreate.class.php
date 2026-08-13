<?php

namespace FreePBX\modules\Sccp_manager;

#[\AllowDynamicProperties]
class formcreate
{
    use \FreePBX\modules\Sccp_Manager\sccpManTraits\helperFunctions;

    public function __construct($parent_class = null) {
        $this->buttonDefLabel = 'chan-sccp';
        $this->buttonHelpLabel = 'site';
    }

    // Shared field wrapper, matching FreePBX core's own convention
    // (see admin/modules/core/views/extensions.php): a label+help-icon
    // column, a value column, and a full-width help-text row below.
    // Used by all addElement* methods except addElementHLP (a collapsible
    // help panel, not a labeled field).
    private function elementOpen($res_id, $label, $secClass = '') {
        ?>
        <div class="element-container">
            <div class="row">
                <div class="form-group <?php echo $secClass; ?>">
                    <div class="col-md-3 sccp-field-label">
                        <label class="control-label" for="<?php echo $res_id; ?>"><?php echo $label; ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="<?php echo $res_id; ?>"></i>
                    </div>
                    <div class="col-md-9">
        <?php
    }

    private function elementCloseRow() {
        ?>
                    </div>
                </div>
            </div>
        <?php
    }

    // The hidden "customise" row used by the sccp-edit/sccp-restore toggle
    // (addElementIE/addElementIS). id/name contract must match
    // assets/js/sccp_manager.js exactly - it is not event-delegated.
    private function elementEditRowOpen($res_id, $promptText, $secClass = '') {
        ?>
            <div class="row" id="edit_<?php echo $res_id; ?>" style="display: none">
                <div class="form-group <?php echo $secClass; ?>">
                    <div class="col-md-3">
                        <i><?php echo $promptText; ?></i>
                    </div>
                    <div class="col-md-9">
        <?php
    }

    private function elementHelpAndClose($res_id, $helpText) {
        ?>
            <div class="row">
                <div class="col-md-12">
                    <span id="<?php echo $res_id;?>-help" class="help-block fpbx-help-block"><?php echo $helpText;?></span>
                </div>
            </div>
        </div>
        <?php
    }

    function addElementIE ($child, $fvalues, $sccp_defaults, $npref) {
        $res_input = '';
        $res_name = '';
        if ($npref == 'sccp_hw_') {
            $this->buttonDefLabel = 'site';
            $this->buttonHelpLabel = 'device';
        }
        // if there are multiple inputs, take the first for res_id and shortId
        $shortId = (string)$child->input[0]->name;
        $res_id = $npref.$shortId;
        if (!empty($metainfo[$shortId])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metainfo[$shortId];
            }
        }

        // --- Add Hidden option
        $res_sec_class ='';
        if (!empty($child ->class)) {
            $res_sec_class = (string)$child ->class;
        }
        if (empty($child->nameseparator)) {
            $child->nameseparator = ' / ';
        }

        $hasSysDefault = !empty($sccp_defaults[$shortId]['systemdefault']);

        $this->elementOpen($res_id, _($child->label), $res_sec_class);

        // Always a plain, directly-editable input - no separate "Customise"
        // click-to-reveal step. When chan-sccp has a system default for this
        // field, it is shown as a placeholder (not a real value): leaving the
        // input untouched submits '' so chan-sccp's own default keeps applying
        // (createDefaultSccpConfig() only writes the directive when data is
        // non-empty); typing a value - even one matching the default - explicitly
        // overrides it. Can have multiple inputs for a field, shown with a separator.
        if ($hasSysDefault) {
            echo '<div class="sccp-value-flex">';
        }
        $i = 0;
        foreach ($child->xpath('input') as $value) {
            $res_n =  (string)$value->name;
            $res_name = $npref . $res_n;
            $curData = $fvalues[$res_n]['data'] ?? '';
            if (empty($value->type)) {
                $value->type = 'text';
            }
            if (empty($value->class)) {
                $value->class = 'form-control';
            }
            if ($i > 0) {
                echo $child->nameseparator;
            }
            echo '<input type="' . $value->type . '" class="' . $value->class . '" id="' . $res_id . '" name="' . $res_name . '" value="' . htmlspecialchars($curData, ENT_QUOTES) . '"';
            if ($hasSysDefault) {
                $sysDefault = $sccp_defaults[$res_n]['systemdefault'] ?? '';
                echo ' placeholder="' . htmlspecialchars($sysDefault, ENT_QUOTES) . '"';
            }
            if (isset($value->options)) {
                foreach ($value->options ->attributes() as $optkey => $optval) {
                    echo  ' '.$optkey.'="'.$optval.'"';
                }
            }
            if (!empty($value->min)) {
                echo  ' min="'.$value->min.'"';
            }
            if (!empty($value->max)) {
                echo  ' max="'.$value->max.'"';
            }
            echo  '>';
            $i ++;
        }
        if ($hasSysDefault) {
            echo '<button type="button" class="btn btn-default sccp-reset-default" data-for="'.$res_id.'">Use '.$this->buttonDefLabel.' defaults</button>';
            echo '</div>';
        }
        $this->elementCloseRow();

        $this->elementHelpAndClose($res_id, _($child->help));
    }

    function addElementIED($child, $fvalues, $sccp_defaults,$npref, $napref) {
        //$Sccp_manager = \FreePBX::create()->Sccp_manager;
        // IED fields are arrays of networks and masks, or ip and ports.
        $res_input = '';
        $res_value = '';
        $opt_at = array();
        $res_n =  (string)$child->name;

        if (!empty($metainfo[$res_n])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metaInfo[$res_n];
            }
        }
    //        $res_value
        $lnhtm = '';
        $res_id = $napref.$child->name;
        //$i = 0;
        $max_row = 255;
        if (!empty($child->max_row)) {
            $max_row = $child->max_row;
        }

        // fvalues are current settings - the encoding depends on where the data is
        // coming from: IED fields in sccpsettings are json, elsewhere they are ; delimited.
        if (!empty($fvalues[$res_n])) {
            if (!empty($fvalues[$res_n]['data'])) {
                $res_value = $this->convertCsvToArray($fvalues[$res_n]['data']);
            }
        }

        if ($res_n == 'srst_ip') {
            $res_value = $this->convertCsvToArray($sccp_defaults[$res_n]['data'] ?? '');
        }
        if (empty($res_value)) {
            $res_value = array((string) $child->default);
        }

        $this->elementOpen($res_id, _($child->label));
                            ?>
                            <?php
                            if (!empty($child->cbutton)) {
                                echo '<div class="form-group form-inline">';
                                foreach ($child->xpath('cbutton') as $value) {
                                    $res_n = $res_id.'[0]['.$value['field'].']';
                                    // res_vf sets the state of the checkbox internal. This is always
                                    // the first array element in $res_value if set
                                    $res_vf = false;
                                    if ($value['value']=='NONE' && empty($res_value)) {
                                        $res_vf = true;
                                    }
                                    if ((isset($res_value[0]['internal'])) || ($res_value[0] == 'internal')) {
                                        $res_vf = true;
                                        // Remove the value from $res_value so that do not add empty row for internal
                                        array_shift($res_value);
                                        // If now have an empty array, add a new empty element
                                        if (count($res_value) == 0) {
                                            // although handle also ip, internal is never set for those arrays
                                            $res_value[0] = array('net'=>"", 'mask' =>"");
                                        }
                                    }
                                    $opt_hide ='';
                                    $opt_class="button-checkbox";
                                    if (!empty($value->option_hide)) {
                                        $opt_class .= " sccp_button_hide";
                                        $opt_hide = ' data-vhide="'.$value->option_hide.'" data-btn="checkbox" data-clhide="'.$value->option_hide['class'].'" ';
                                    }
                                    if (!empty($child->option_show)) {
                                        if (empty($opt_hide)) {
                                            $opt_hide =' class="sccp_button_hide" ';
                                        }
                                        $opt_hide .= ' data-vshow="'.$child->option_show.'" data-clshow="'.$child->option_show['class'].'" ';
                                    }

                                    if (!empty($value->option_disabled)) {
                                        $opt_class .= " sccp_button_disabled";
                                        $opt_hide = ' data-vhide="'.$value->option_disabled.'" data-btn="checkbox" data-clhide="'.$value->option_disabled['class'].'" ';
                                    }

                                    if (!empty($value->class)) {
                                        $opt_class .= " ".(string)$value->class;
                                    }

                                    echo '<span class="'.$opt_class.'"'.$opt_hide.'><button type="button" class="btn '.(($res_vf) ? 'active':"").'" data-color="primary">';
                                    echo '<i class="state-icon'. (($res_vf)?' fa fa-check-square-o':''). '"></i> ';
                                    echo $value.'</button><input type="checkbox" name="'. $res_n.'" class="hidden" '. (($res_vf)?'checked="checked"':'') .'/></span>';
                                }
                                echo '</div>';
                            }
                            $opt_class = $res_id."-gr";
                            if (!empty($child->class)) {
                                $opt_class .= " ".(string)$child->class;
                            }
                            echo '<div class = "'.$opt_class.'">';
                            $i=1;
                            foreach ($res_value as $addrArr) {
                                ?>
                                <div class = "<?php echo $res_id;?> sccp-ied-row form-group form-inline" data-nextid=<?php echo $i;?> id= <?php echo $res_id . $i;?>>
                                <?php
                                foreach ($child->xpath('input') as $value) {
                                    $field_id = (string)$value['field'];
                                    $res_n = $res_id.'['.$i.']['.$field_id.']';
                                    $opt_at[$field_id]['class'] = $opt_at[$field_id]['class'] ?? '';
                                    if (!empty($value->class)) {
                                        $opt_at[$field_id]['class']='form-control ' .(string)$value->class;
                                    }

                                    $defValue = (isset($addrArr[$field_id])) ? $addrArr[$field_id]: "";
                                    echo '<input type="text" name="'. $res_n.'" class="'.$opt_at[$field_id]['class'].'" value="'. $defValue .'"';


                                    if (isset($value->options)) {
                                        foreach ($value->options ->attributes() as $optkey => $optval) {
                                            $opt_at[$field_id]['options'][$optkey]=(string)$optval;
                                            $opt_at[$field_id]['nameseparator'] = (null !== (string)$value['nameseparator']) ? (string)$value['nameseparator'] : '';
                                            echo  ' '.$optkey.'="'.$optval.'"';
                                        }
                                    }
                                    echo '> '.(string)$value['nameseparator'].' ';
                                }

                                if (!empty($child->add_pluss)) {
                                    if ($i <= count($res_value)) {
                                        echo '<button type="button" class="btn btn-danger btn-lg input-js-remove" id="'.$res_id.$i.'-btn-del" data-id="'.$res_id.$i.'"><i class="fa fa-minus pull-right"></i></button>';
                                    }
                                    // only add plus button to the last row
                                    if ($i == count($res_value)) {
                                        echo '<button type="button" class="btn btn-primary btn-lg input-js-add" id="'.$res_id.$i.'-btn-add" data-id="'.$res_id.'" data-row="'.$i.'" data-for="'.$res_id.'" data-max="'.$max_row.'"data-json="'.bin2hex(json_encode($opt_at)).'"><i class="fa fa-plus pull-right"></i></button>';
                                    }
                                }
                                echo '</div>';
                                $i++;
                            }
                            ?>
                                </div>
                            <?php
                            if (!empty($child->addbutton)) {
                                echo '<div class = "'.$res_id.'-gr">';
                                echo '<input type="button" id="'.$res_id.'-btn" data-id="'.$res_id.'" data-for="'.$res_id.'" data-max="'.$max_row.'"data-json="'.bin2hex(json_encode($opt_at)).'" class="input-js-add" value="'._($child->addbutton).'" />';
                                echo '</div>';
                            }
                            ?>
        <?php
        $this->elementCloseRow();
        $this->elementHelpAndClose($res_id, _($child->help));
    }

    function addElementIS($child, $fvalues, $sccp_defaults,$npref, $disabledButtons) {
      if ($npref == 'sccp_hw_') {
          $this->buttonDefLabel = 'site';
          $this->buttonHelpLabel = 'device';
      }
        $res_n =  (string)$child->name;
        $res_id = $npref.$res_n;
        $res_ext = str_replace($npref,'',$res_n);
        if (!empty($metainfo[$res_n])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metaInfo[$res_n];
            }
        }

        // --- Add Hidden option
        $res_sec_class ='';
        if (!empty($child ->class)) {
            $res_sec_class = (string)$child ->class;
        }
        $hasSysDefault = !empty($sccp_defaults[$res_n]['systemdefault']);
        $curData = $fvalues[$res_n]['data'] ?? '';

        // set res_v according to precedence Default here, value here, supplied value
        $res_v = '';
        if (!empty($child->default)) {
            $res_v = (string)$child->default;
        }
        if (!empty($child->value)) {
             $res_v = (string)$child->value;
        }
        if ($curData !== '') {
            $res_v = $curData;
        } elseif ($hasSysDefault) {
            // No site-specific value set and chan-sccp has its own default for
            // this field - leave nothing pre-selected (see $renderButtons) rather
            // than defaulting res_v to the XML default/value above, so an
            // untouched radio group submits nothing and chan-sccp's own default
            // keeps applying (matches addElementIE's placeholder-input approach).
            $res_v = '';
        }

        // Renders the actual radio button set.
        $renderButtons = function() use ($child, $disabledButtons, $res_id, &$res_v) {
            $i = 0;
            $opt_hide = '';
            if (!empty($child->option_hide)) {
                $opt_hide = ' class="sccp_button_hide" data-vhide="'.$child->option_hide.'" data-clhide="'.$child->option_hide['class'].'" ';
            }
            if (!empty($child->option_show)) {
                if (empty($opt_hide)) {
                    $opt_hide =' class="sccp_button_hide" ';
                }
                $opt_hide .= ' data-vshow="'.$child->option_show.'" data-clshow="'.$child->option_show['class'].'" ';
            }
            foreach ($child->xpath('button') as $value) {
                $opt_disabled = '';
                if (in_array($value, $disabledButtons )) {
                    $opt_disabled = 'disabled';
                }
                $val_check = strtolower((string)$value['value']);
                if ($val_check == strtolower($res_v)) {
                    $val_check = "checked";
                } else {
                    if ($val_check == '' || $val_check == 'none' ) {
                       if (strtolower($res_v) == 'none' || $res_v == '' )  {
                          $val_check = "checked";
                       } else {$val_check = "";}
                    } else {$val_check = "";}
                }
                echo "<input type=radio name= {$res_id} id={$res_id}_{$i} value='{$value['value']}' {$val_check} {$opt_hide} {$opt_disabled}>";
                echo "<label for= {$res_id}_{$i}>{$value}</label>";
                $i++;
            }
        };

        $this->elementOpen($res_id, _($child->label), $res_sec_class);
        if ($hasSysDefault) {
            echo '<div class="sccp-value-flex">';
        }
        ?>
                    <div class="radioset"><?php $renderButtons(); ?></div>
        <?php
        if ($hasSysDefault) {
            echo '<button type="button" class="btn btn-default sccp-reset-radio-default" data-for="'.$res_id.'">Use '.$this->buttonDefLabel.' defaults</button>';
            if ($curData === '') {
                $sysDefault = $sccp_defaults[$res_n]['systemdefault'] ?? '';
                echo ' <small class="text-muted">('.htmlspecialchars($sysDefault, ENT_QUOTES).')</small>';
            }
            echo '</div>';
        }
        $this->elementCloseRow();

        $this->elementHelpAndClose($res_id, _($child->help));
    }

    function addElementSL($child, $fvalues, $sccp_defaults,$npref, $installedLangs) {
    //       Input element Select SLS - System Language
        $res_n =  (string)$child ->name;
        $res_id = $npref.$res_n;
        $child->value ='';
        // $select_opt is an associative array for these types.
        if (!empty($metainfo[$res_n])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metaInfo[$res_n];
            }
        }
        switch ($child['type']) {
            case 'SLS':
                $syslangs = array();
                if (\FreePBX::Modules()->checkStatus("soundlang")) {
                   $syslangs = \FreePBX::Soundlang()->getLanguages();
                }
                $select_opt= $syslangs;
                break;
            case 'SLTD':
                // Device Language
                $select_opt = array('xx' => 'No language packs found');
                if (!empty($installedLangs['languages']['have'])) {
                    $select_opt = (array)$installedLangs['languages']['have'];
                }
                break;
            case 'SLTN':
                // Network Language
                $select_opt = array('xx' => 'No country packs found');
                if (!empty($installedLangs['countries']['have'])) {
                    $select_opt = (array)$installedLangs['countries']['have'];
                }
                break;
            case 'SLZ':
                $timeZoneOffsetList = array('-12' => 'GMT -12', '-11' => 'GMT -11', '-10' => 'GMT -10', '-09' => 'GMT -9',
                                   '-08' => 'GMT -8',  '-07' => 'GMT -7',  '-06' => 'GMT -6', '-05' => 'GMT -5',
                                   '-04' => 'GMT -4',  '-03' => 'GMT -3',  '-02' => 'GMT -2', '-01' => 'GMT -1',
                                   '00'  => 'GMT', '01' => 'GMT +1',  '02'  => 'GMT +2', '03'  => 'GMT +3',
                                   '04'  => 'GMT +4',   '05' => 'GMT +5',  '06'  => 'GMT +6', '07'  => 'GMT +7',
                                   '08'  => 'GMT +8',   '09' => 'GMT +9',  '10'  => 'GMT +10', '11'=> 'GMT +11', '12' => 'GMT +12');
                $select_opt= $timeZoneOffsetList;
                break;
            case 'SLA':
            $select_opt = array();
                if (!empty($fvalues[$res_n])) {
                    if (!empty($fvalues[$res_n]['data'])) {
                        $res_value = explode(';', $fvalues[$res_n]['data']);
                    }
                    if (empty($res_value)) {
                        $res_value = array((string) $child->default);
                    }
                    foreach ($res_value as $key) {
                        $select_opt[$key]= $key;
                    }
                }

            case 'SLM':
                if (function_exists('music_list')) {
                    $moh_list = music_list();
                }
                if (!is_array($moh_list)) {
                    $moh_list = array('default');
                }
                $select_opt= $moh_list;
                break;
            case 'SLD':
                $day_format = array("D.M.Y", "D.M.YA", "Y.M.D", "YA.M.D", "M-D-Y", "M-D-YA", "D-M-Y", "D-M-YA", "Y-M-D", "YA-M-D", "M/D/Y", "M/D/YA",
                   "D/M/Y", "D/M/YA", "Y/M/D", "YA/M/D", "M/D/Y", "M/D/YA");
                $select_opt= $day_format;
                break;
            case 'SLK':
                $softKeyList = array();
                $softKeyList = \FreePBX::Sccp_manager()->aminterface->sccp_list_keysets();
                $select_opt= $softKeyList;
                break;
            case 'SLP':
                $dialplan_list = array();
                foreach (\FreePBX::Sccp_manager()->getDialPlanList() as $tmpkey) {
                    $tmp_id = $tmpkey['id'];
                    $dialplan_list[$tmp_id] = $tmp_id;
                }
                $select_opt= $dialplan_list;
                break;
            case 'SL':
                $select_opt = array();
                break;
        }
        if (empty($child->class)) {
            $child->class = 'form-control';
        }
        if (!empty($fvalues[$res_n])) {
            if (!empty($fvalues[$res_n]['data'])) {
                $child->value = $fvalues[$res_n]['data'];
            }
        }
        if (empty($child->value)) {
            if (!empty($child->default)) {
                $child->value = $child->default;
            }
        }
        $this->elementOpen($res_id, _($child->label));
        ?>
                        <div class = "lnet form-group form-inline" data-nextid=1>
                            <?php
                            echo  '<select name="'.$res_id.'" class="'. $child->class . '" id="' . $res_id . '">';
                            foreach ($select_opt as $key => $val) {
                                if (is_array($val)) {
                                    $opt_key = (isset($val['id'])) ? $val['id'] : $key;
                                    $opt_val = (isset($val['val'])) ? $val['val'] : $val;
                                } else if (\FreePBX::Sccp_manager()->is_assoc($select_opt)){
                                    // have associative array
                                    $opt_key = $key;
                                    $opt_val = $val;
                                } else {
                                    // Have simple array
                                    $opt_key = $val;
                                    $opt_val = $val;
                                }
                                echo '<option value="' . $opt_key . '"';
                                if ($opt_key == $child->value) {
                                    echo ' selected="selected"';
                                }
                                echo "> {$opt_val} </option>";
                            }
                            ?>
                            </select>
                        </div>
        <?php
        $this->elementCloseRow();
        $this->elementHelpAndClose($res_id, _($child->help));
    }

    function addElementSLNA($child, $fvalues, $sccp_defaults,$npref, $installedLangs) {
    //       Input element Select SLS - System Language with add from external
        global $amp_conf;
        $res_n =  (string)$child ->name;
        $res_id = $npref.$res_n;
        $child->value ='';
        $selectArray = array();
        // $select_opt is an associative array for these types.
        if (!empty($metainfo[$res_n])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metaInfo[$res_n];
            }
        }

        switch ($child['type']) {
            case 'SLDA':
                $select_opt = array('xx' => 'No language packs found');
                if (!empty($installedLangs['languages']['have'])) {
                    $select_opt = $installedLangs['languages']['have'];
                }
                $selectArray = $installedLangs['languages']['available'] ?? array();
                $requestType = 'locale';
                break;

            case 'SLNA':
                $select_opt = array('xx' => 'No country packs found');
                if (!empty($installedLangs['countries']['have'])) {
                    $select_opt = $installedLangs['countries']['have'];
                }
                $selectArray = $installedLangs['countries']['available'] ?? array();
                $requestType = 'country';
              break;
        }


        if (empty($child->class)) {
            $child->class = 'form-control';
        }
        if (!empty($fvalues[$res_n])) {
            if (!empty($fvalues[$res_n]['data'])) {
                $child->value = $fvalues[$res_n]['data'];
            }
        }
        if (empty($child->value)) {
            if (!empty($child->default)) {
                $child->value = $child->default;
            }
        }

        include($amp_conf['AMPWEBROOT'] . '/admin/modules/sccp_manager/views/getFileModal.html');

        $this->elementOpen($res_id, _($child->label));
        ?>
                    <div class="row">
                    <div class="col-sm-3">
                        <div class = "lnet form-group form-inline" data-nextid=1>
                            <?php
                            echo  '<select name="'.$res_id.'" class="'. $child->class . '" id="' . $res_id . '">';
                            foreach ($select_opt as $key => $val) {
                                    $opt_key = $key;
                                    $opt_val = $val;
                                echo '<option value="' . $opt_val . '"';
                                if ($opt_val == $child->value) {
                                    echo ' selected="selected"';
                                }
                                echo "> {$opt_val} </option>";
                            }
                            ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-4">
                      <button type="button" class="btn btn-primary btn-lg" id="<?php echo $requestType;?>" data-toggle="modal" data-target=".get_ext_file_<?php echo $requestType;?>"><i class="fa fa-bolt"></i> <?php echo _("Get $requestType from Provisioner");?>
                      </button>
                    </div>
                    </div>
        <?php
        $this->elementCloseRow();
        $this->elementHelpAndClose($res_id, _($child->help));
    }

    function addElementSD($child, $fvalues, $sccp_defaults,$npref) {
      /*
      *    Input element Select SDM  - Model List
      *                         SDMS - Sip model List
      *                         SDE  - Extension List
      */
        $res_n =  (string)$child ->name;
        $res_id = $npref.$res_n;

        if (!empty($metainfo[$res_n])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metaInfo[$res_n];
            }
        }

        if (empty($child->class)) {
            $child->class = 'form-control';
        }
        switch ($child['type']) {
            case 'SDM':
                $model_list = \FreePBX::Sccp_manager()->dbinterface->getDb_model_info('ciscophones', 'model');
                $select_opt= $model_list;
                break;
            case 'SDMS':
                $model_list = \FreePBX::Sccp_manager()->dbinterface->getDb_model_info('sipphones', 'model');
                $select_opt= $model_list;
                break;
            case 'SDML':
                // Sccp extensions
                $assignedExts = \FreePBX::Sccp_manager()->dbinterface->getSccpDeviceTableData('getAssignedExtensions');
                $select_opt = \FreePBX::Sccp_manager()->dbinterface->getSccpDeviceTableData('SccpExtension');
                foreach ($assignedExts as $name => $nameArr ) {
                      $select_opt[$name]['label'] .= " -  in use";
                }
                $child->default = $fvalues['defaultLine'] ?? '';
                break;
            case 'SDMF':
                // Sip extensions
                $select_opt = \FreePBX::Sccp_manager()->dbinterface->getSipTableData('extensionList');
                $child->default = $fvalues['defaultLine'] ?? '';
                break;
            case 'SDE':
                $extension_list = \FreePBX::Sccp_manager()->dbinterface->getDb_model_info('extension', 'model');
                $extension_list[] = array( 'model' => 'NONE', 'vendor' => 'CISCO', 'dns' => '0');
                foreach ($extension_list as &$data) {
                    $d_name = explode(';', $data['model']);
                    if (is_array($d_name) && (count($d_name) > 1)) {
                        $data['description'] = count($d_name).'x '.$d_name[0];
                    } else {
                        $data['description'] = $data['model'];
                    }
                }
                unset($data);
                $select_opt= $extension_list;
                break;
            case 'SDD':
                $device_list = \FreePBX::Sccp_manager()->dbinterface->getSccpDeviceTableData("SccpDevice");
                $device_list[]=array('name' => 'NONE', 'description' => 'No Device');
                $select_opt = $device_list;
                break;
        }
        $this->elementOpen($res_id, _($child->label));
        ?>
                    <div class = "lnet form-group form-inline" data-nextid=1> <?php
                            echo  '<select name="'.$res_id.'" class="'. $child->class . '" id="' . $res_id . '"';
                    if (isset($child->options)) {
                        foreach ($child->options->attributes() as $optkey => $optval) {
                            echo  ' '.$optkey.'="'.$optval.'"';
                        }
                    }
                            echo  '>';

                            $fld  = (string)$child->select['name'];
                            $flv  = (string)$child->select['name'];
                            $flv2 = (string)$child->select['addlabel'];
                            $flk  = (string)$child->select['dataid'];
                            $flkv = (string)$child->select['dataval'];
                            $key  = (string)$child->default;
                    if (!empty($fvalues[$res_n])) {
                        if (!empty($fvalues[$res_n]['data'])) {
                            $child->value = $fvalues[$res_n]['data'];
                            $key = $fvalues[$res_n]['data'];
                        }
                    }
                    foreach ($select_opt as $data) {
                        echo '<option value="' . ($data[$fld] ?? '') . '"';
                        if ($key == ($data[$fld] ?? '')) {
                            echo ' selected="selected"';
                        }
                        if (!empty($flk)) {
                            echo ' data-id="'.($data[$flk] ?? '').'"';
                        }
                        if (!empty($flkv)) {
                            echo ' data-val="'.($data[$flkv] ?? '').'"';
                        }
                        echo '>' . ($data[$flv] ?? '');
                        if (!empty($flv2)) {
                            echo ' / '.($data[$flv2] ?? '');
                        }
                        echo '</option>';
                    }

                    ?>
                    </select>
                    </div>
        <?php
        $this->elementCloseRow();
        $this->elementHelpAndClose($res_id, _($child->help));
    }

    function addElementITED($child, $fvalues, $sccp_defaults, $npref, $napref) {
        $res_input = '';
        $res_na =  (string)$child->name;

    //        $res_value
        $lnhtm = '';
        $res_id = $napref.$child->name;
        $i = 0;

        if (!empty($fvalues[$res_na])) {
            if (!empty($fvalues[$res_na]['data'])) {
                $res_value = explode(';', $fvalues[$res_na]['data']);
            }
        }
        if (empty($res_value)) {
            $res_value = array((string) $child->default);
        }
        echo "<table class=table table-striped id=dp-table-{$res_id}>";

        foreach ($res_value as $dat_v) {
            echo '<tr data-nextid="'.($i+1).'" class="'.$res_id.'" id="'.$res_id.'-row-'.($i).'"> ';
            if (!empty($child->label)) {
                echo '<td class=""> <div class="input-group">'.$child->label.'</div></td>';
            }

            $res_vf = explode('/', $dat_v);
            $i2 = 0;

            foreach ($child->xpath('element') as $value) {
                $fields_id = (string)strtolower($value['field']);
                $res_n  = $res_id.'['.$i.']['.$fields_id.']';
                $res_ni = $res_id.'_'.$i.'_'.$fields_id;

                $opt_at[$fields_id]['display_prefix']=(string)$value['display_prefix'];
                $opt_at[$fields_id]['display_sufix']=(string)$value['display_sufix'];

                if (empty($value->options->class)) {
                    $opt_at[$fields_id]['options']['class']='form-control';
                }
                $opt_at[$fields_id]['type']=(string)$value['type'];
                $res_opt['addon'] ='';
                if (isset($value->options)) {
                    foreach ($value->options ->attributes() as $optkey => $optval) {
                        $opt_at[$fields_id]['options'][$optkey]=(string)$optval;
                        $res_opt['addon'] .=' '.$optkey.'="'.$optval.'"';
                    }
                }

                echo '<td class="">';
                $res_opt['inp_st'] = '<div class="input-group"> <span class="input-group-addon" id="basep_'.$res_n.'">'.$opt_at[$fields_id]['display_prefix'].'</span>';
                $res_opt['inp_end'] = '<span class="input-group-addon" id="bases_'.$res_n.'">'.$opt_at[$fields_id]['display_sufix'].'</span></div>';
                switch ($value['type']) {
                    case 'date':
                        echo $res_opt['inp_st'].'<input type="date" name="'. $res_n.'" value="'.($res_vf[$i2] ?? '').'"'.$res_opt['addon']. '>'.$res_opt['inp_end'];
                        break;
                    case 'number':
                        echo $res_opt['inp_st'].'<input type="number" name="'. $res_n.'" value="'.($res_vf[$i2] ?? '').'"'.$res_opt['addon']. '>'.$res_opt['inp_end'];
                        break;
                    case 'input':
                        echo $res_opt['inp_st'].'<input type="text" name="'. $res_n.'" value="'.($res_vf[$i2] ?? '').'"'.$res_opt['addon']. '>'.$res_opt['inp_end'];
                        break;
                    case 'title':
                        if ($i > 0) {
                            break;
                        }
                    case 'label':
                        $opt_at[$fields_id]['data'] = (string)$value;
                        echo '<label '.$res_opt['addon'].' >'.(string)$value.'</label>';
                        break;
                    case 'select':
                        echo  $res_opt['inp_st'].'<select name="'.$res_n.'" id="' . $res_n . '"'. $res_opt['addon'].'>';
                        $opt_at[$fields_id]['data']='';
                        foreach ($value->xpath('data') as $optselect) {
                            $opt_at[$fields_id]['data'].= (string)$optselect.';';
                            echo '<option value="' . $optselect. '"';
                            if (strtolower((string)$optselect) == strtolower((string)($res_vf[$i2] ?? ''))) {
                                echo ' selected="selected"';
                            }
                            echo '>' . (string)$optselect. '</option>';
                        }
                        echo  '</select>'.$res_opt['inp_end'];
                        break;
                }
                echo '</td>';
                $i2 ++;
            }
            echo '<td><input type="button" id="'.$res_id.'-btn" data-id="'.($i).'" data-for="'.$res_id.'" data-json="'.bin2hex(json_encode($opt_at)).'" class="table-js-add" value="+" />';
            if ($i > 0) {
                echo '<input type="button" id="'.$res_id.'-btndel" data-id="'.($i).'" data-for="'.$res_id.'" class="table-js-del" value="-" />';
            }

            echo '</td></tr>';
            $i++;
        }
        echo '</table>';
    }

    function addElementHLP($child, $fvalues, $sccp_defaults,$npref) {
        $res_n =  (string)$child ->name;
        $res_id = $npref.$res_n;
        if (empty($child->class)) {
            $child->class = 'form-control';
        }
        ?>

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-info-circle"></i> <nbsp> <?php echo _($child->label);?>
                <a data-toggle="collapse" href="<?php echo '#'.$res_id;?>"><i class="fa fa-plus pull-right"></i></a></h3>
            </div>
            <div class="panel-body collapse" id="<?php echo $res_id;?>">
        <?php
        foreach ($child->xpath('element') as $value) {
            switch ($value['type']) {
                case 'p':
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                    echo '<'.$value['type'].'>'._((string)$value).'</'.$value['type'].'>';
                    break;
                case 'table':
                    echo '<'.$value['type'].' class="table" >';
                    foreach ($value->xpath('row') as $trow) {
                        echo '<tr>';
                        foreach ($trow->xpath('col') as $tcol) {
                            echo '<td>'._((string)$tcol).'</td>';
                        }
                        echo '</tr>';
                    }
                    echo '</'.$value['type'].'>';
                    break;
            }
        }
        ?>
            </div>
        </div>
        <?php
    }

    function addElementSLTZN($child, $fvalues, $sccp_defaults,$npref) {
    //       Input element Select SLTZN - System Time Zone
        $res_n =  (string)$child ->name;
        $res_id = $npref.$res_n;
        $child->value ='';

        if (!empty($metainfo[$res_n])) {
            if ($child->meta_help == '1' || $child->help == 'Help!') {
                $child->help = $metaInfo[$res_n];
            }
        }

        if (empty($child->class)) {
            $child->class = 'form-control';
        }

        if (!empty($fvalues[$res_n])) {
            if (!empty($fvalues[$res_n]['data'])) {
                $child->value = $fvalues[$res_n]['data'];
            }
        }

        $child->value = \date_default_timezone_get();
        $this->elementOpen($res_id, _($child->label));
        echo $child->value;
        $this->elementCloseRow();
        $this->elementHelpAndClose($res_id, _($child->help));
    }
}

?>
