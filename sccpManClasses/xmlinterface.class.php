<?php

/**
 *
 * Core Comsnd Interface
 *
 *
 */
/* !TODO!: -TODO-: Would you like to use my XSD file to check if the provided template file is a correct cisco cnf.xml file ?
 * !TODO!: -TODO-: I just don't understand how to use it here.. To check the incoming pattern ? To check the result of my script ?
 * !TODO!: -TODO-: The most correct variant is to generate xml based on XSD template.
 */

namespace FreePBX\modules\Sccp_manager;

#[\AllowDynamicProperties]
class xmlinterface
{
    use \FreePBX\modules\Sccp_manager\sccpManTraits\helperFunctions;
    private $val_null = 'NONE'; /// REPLACE to null Field

    public function __construct($parent_class = null)
    {
        $this->paren_class = $parent_class;
        $this->langCodeArray = array(
            'Arabic_Saudi_Arabia' => 'ar',
            'Bulgarian_Bulgaria' => 'bg',
            'Czech_Czech_Republic' => 'cz',
            'Danish_Denmark' => 'da',
            'German_Germany' => 'de',
            'Greek_Greece' => 'el',
            'AU_English_United_States' => 'en',
            'English_United_Kingdom' => 'en',
            'English_United_States' => 'en',
            'Spanish_Spain' => 'es',
            'Estonian_Estonia' => 'et',
            'Finnish_Finland' => 'fi',
            'French_Canada' => 'fr',
            'French_France' => 'fr',
            'Hebrew_Israel' => 'he',
            'Croatian_Croatia' => 'hr',
            'Hungarian_Hungary' => 'hu',
            'Italian_Italy' => 'it',
            'Japanese_Japan' => 'ja',
            'Korean_Korea_Republic' => 'ko',
            'Lithuanian_Lithuania' => 'lt',
            'Latvian_Latvia' => 'lv',
            'Dutch_Netherlands' => 'nl',
            'Norwegian_Norway' => 'no',
            'Polish_Poland' => 'pl',
            'Portuguese_Brazil' => 'pt',
            'Portuguese_Portugal' => 'pt',
            'Romanian_Romania' => 'ro',
            'Russian_Russian_Federation' => 'ru',
            'Slovak_Slovakia' => 'sk',
            'Slovenian_Slovenia' => 'sl',
            'Serbian_Republic_of_Montenegro' => 'sr',
            'Serbian_Republic_of_Serbia' => 'rs',
            'Swedish_Sweden' => 'sv',
            'Thai_Thailand' => 'th',
            'Turkish_Turkey' => 'tr',
            'Chinese_China' => 'cn',
            'Chinese_Taiwan' => 'zh'
        );
    }

    public function info()
    {
        $Ver = '13.0.6';
        return array('Version' => $Ver,
            'about' => 'Create XML data interface ver: ' . $Ver);
    }

    function create_default_XML($store_path = '', $data_values = array(), $model_information = array(), $lang_info = array())
    {
        $data_path = $data_values['tftp_path'];
        if (empty($store_path) || empty($data_path) || empty($data_values)) {
            return;
        }
        $def_xml_fields = array('authenticationURL',       'informationURL',       'messagesURL',       'servicesURL',       'directoryURL',
                                'secureauthenticationURL', 'secureinformationURL', 'securemessagesURL', 'secureservicesURL', 'securedirectoryURL', 'secureidleURL',
                                'proxyServerURL', 'idleTimeout', 'idleURL');
        $def_xml_locale = array('userLocale', 'networkLocaleInfo', 'networkLocale');
        $xml_name = $store_path . '/XMLDefault.cnf.xml';
        $xml_template = $data_values['tftp_path'] . '/templates/XMLDefault.cnf.xml_template';

        if (file_exists($xml_template)) {
            $xml_work = simplexml_load_file($xml_template);
            $xnode = &$xml_work->callManagerGroup->members;
            $bind_tmp = $this->get_server_sccp_bind($data_values);
            //error_log("bind_tmp:".print_r($bind_tmp, true), 0);
            $ifc = 0;
            foreach ($bind_tmp as $bind_value) {
                $xnode_obj = clone $xnode->member;
                $xnode_obj['priority'] = $ifc;
                $xnode_obj->callManager->name = $data_values['servername'];
                $xnode_obj->callManager->ports->ethernetPhonePort = $bind_value['port'];
                $xnode_obj->callManager->processNodeName = $bind_value['ip'];
                if ($ifc === 0) {
                    $this->replaceSimpleXmlNode($xnode->member, $xnode_obj);
                } else {
                    $this->appendSimpleXmlNode($xnode->member, $xnode_obj);
                }
                $ifc++;
            }

            $this->replaceSimpleXmlNode($xml_work->callManagerGroup->members, $xnode);

            foreach ($def_xml_fields as $value) {
                if (!empty($data_values['dev_' . $value])) {
                    $xml_work->$value = trim($data_values['dev_' . $value]);
                } else {
                    $node = $xml_work->$value;
                    if (!empty($node)) {
                        unset($node[0][0]);
                    }
                }
            }
            foreach ($def_xml_locale as $key) {
                if (!empty($xml_work->$key)) {
                    $xnode = &$xml_work->$key;
                    switch ($key) {
                        case 'userLocale':
                        case 'networkLocaleInfo':
                            if ($key == 'networkLocaleInfo') {
                                $lang = $data_values['netlang'];
                            } else {
                                $lang = $data_values['devlang'];
                            }
                            if (isset($lang_info[$lang])) {
                                $xnode->name = $lang_info[$lang]['locale'];
                                $xnode->langCode = $lang_info[$lang]['code'];
                            } else {
                                $xnode->name = '';
                                $xnode->langCode = '';
                            }
                            break;
                        case 'networkLocale':
                            $lang = $data_values['netlang'];
                            if (isset($lang_info[$lang])) {
                                $xnode = $lang_info[$lang]['language'];
                            } else {
                                $xnode = '';
                            }
                            break;
                    }
                }
            }

            foreach ($model_information as $var) {
                if (!empty($var['loadinformationid'])) {
                    $node = $xml_work->addChild($var['loadinformationid'], $var['loadimage']);
                    $node->addAttribute('model', $var['vendor'] . ' ' . $var['model']);
                }
            }
            $this->saveXml($xml_work, $xml_name);  // Save  XMLDefault1.cnf.xml
        }
    }

    /**
     * Append the <addOnModules> block for a device that has expansion modules.
     *
     * Written here rather than while walking the template, because it used to be
     * emitted from inside the branch that handles the template's <loadInformation>
     * element, and two of the shipped templates have no such element: the ones for
     * the 7975 and for the 797x family. Those are exactly the models that carry a
     * sidecar and never got one provisioned, which is upstream report
     * chan-sccp/chan-sccp#623. Emitting it from here makes the block independent of
     * what the template happens to contain, and also stops the document being
     * modified while the loop above is iterating it.
     *
     * The field holds one model name; a pair of identical sidecars is stored as one
     * value, '7916;7916', because the realtime backend splits a column on the
     * semicolon into repeated addon variables for the driver.
     *
     * @param \SimpleXMLElement $xml_work  device document being built
     * @param array             $dev_config device row, with 'addon' and 'addon_info'
     */
    private function addAddOnModules($xml_work, $dev_config)
    {
        if (empty($dev_config['addon'])) {
            return;
        }
        $xnode = null;
        $ti = 1;
        foreach (explode(';', (string) $dev_config['addon']) as $add_key) {
            $add_key = trim($add_key);
            if ($add_key === '' || empty($dev_config['addon_info'][$add_key])) {
                continue;
            }
            if ($xnode === null) {
                // Created on first use, so a device whose addon has no firmware on
                // record does not get an empty element.
                $xnode = $xml_work->addChild('addOnModules');
            }
            $xnode_obj = $xnode->addChild('addOnModule');
            $xnode_obj->addAttribute('idx', (string) $ti);
            $xnode_obj->addChild('loadInformation', $dev_config['addon_info'][$add_key]);
            $ti++;
        }
    }

    function create_SEP_XML($store_path, $data_values, $dev_config, $dev_id, $lang_info = array())
    {
        // TODO: $data_values are system wide defaults, $dev_config are specific device values.
        // Need to merge the two arrays so that device specific values override system values


        $data_values = array_merge($data_values, $dev_config);
        $var_xml_general_fields = array('authenticationurl' => 'dev_authenticationURL', 'informationurl' => 'dev_informationURL', 'messagesurl' => 'dev_messagesURL',
            'servicesurl' => 'dev_servicesURL', 'directoryurl' => 'dev_directoryURL', 'idleurl' => 'dev_idleURL',
            'secureauthenticationurl' => 'dev_secureauthenticationURL',
            'secureinformationurl' => 'dev_secureinformationURL', 'securemessagesurl'=>'dev_securemessagesURL',
            'secureservicesurl'=>'dev_secureservicesURL',  'securedirectoryurl'=>'dev_securedirectoryURL', 'secureidleurl' => 'dev_secureidleURL',
            'proxyserverurl' => 'dev_proxyServerURL', 'idletimeout' => 'dev_idleTimeout',
            'sshuserid' => 'dev_sshUserId', 'sshpassword' => 'dev_sshPassword', 'deviceprotocol' => 'dev_deviceProtocol',
            'phonepersonalization' => 'phonepersonalization'
        );
        $var_xml_general_vars = array('capfAuthMode' => 'null', 'capfList' => 'null', 'mobility' => 'null',
            'phoneServices' => 'null', 'certHash' => 'null',
            'deviceSecurityMode' => '1');

        $data_path = $dev_config['tftp_templates_path'];
        if (empty($data_path)) {
            freepbx_log(FPBX_LOG_ERROR, 'sccp_manager: create_SEP_XML called without tftp_templates_path');
            return false;
        }

        $store_path = $dev_config['tftp_store_path'];
        if (empty($store_path)) {
            freepbx_log(FPBX_LOG_ERROR, 'sccp_manager: create_SEP_XML called without tftp_store_path');
            return false;
        }

        if (!empty($dev_config['nametemplate'])) {
            $xml_template = "{$data_path}/" . basename((string) $dev_config['nametemplate']);   // basename keeps the template inside data_path
        } else {
            $xml_template = "{$data_path}/SEP0000000000.cnf.xml_79df_template";
        }
        $safe_dev_id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $dev_id);                  // device id feeds a write path; keep it to a bare token
        $xml_name = "{$store_path}/{$safe_dev_id}.cnf.xml";
        if (!file_exists($xml_template)) {
            freepbx_log(FPBX_LOG_ERROR, 'sccp_manager: hardware template not found: ' . $xml_template);
            return false;
        }
        $xml_work = simplexml_load_file($xml_template);

        foreach ($var_xml_general_vars as $key => $data) {
            if (isset($xml_work->$key)) {
                if ($data != 'null') {
                    $xml_work->$key = $data;
                } else {
                    $node = $xml_work->$key;
                    unset($node[0][0]);
                }
            }
        }

        foreach ($xml_work as $key => $data) {
//              Set System global Values
            $key_l = strtolower($key);
            if (!empty($var_xml_general_fields[$key_l])) {
                $xml_work->$key = $data_values[$var_xml_general_fields[$key_l]];
            }
//              Set section Values
            $xml_node = $xml_work->$key;
            switch ($key_l) {
                case 'devicepool':
                    $xml_node = $xml_work->$key;
                    foreach ($xml_work->$key->children() as $dkey => $ddata) {
                        switch (strtolower($dkey)) {
                            case 'datetimesetting':
                                $xnode = &$xml_node->$dkey;
                                $tz_id = $data_values['ntp_timezone'];
                                $TZdata = $data_values['ntp_timezone_id'];
                                if (empty($TZdata)) {
                                    $TZdata = array('offset' => '0', 'daylight' => '', 'cisco_code' => 'Greenwich Standard Time');
                                }
                                $xnode->name = $tz_id;
                                $xnode->dateTemplate = $data_values['dateformat'];
                                $xnode->timeZone = $TZdata['cisco_code'];
//                                    $xnode->timeZone = $tz_id.' Standard'.((empty($TZdata['daylight']))? '': '/'.$TZdata['daylight']).' Time';

                                if ($data_values['ntp_config_enabled'] == 'on') {
                                    $xnode->ntps->ntp->name = $data_values['ntp_server'];
                                    $xnode->ntps->ntp->ntpMode = $data_values['ntp_server_mode'];
                                } else {
                                    $xnode->ntps = null;
                                }
                                // Ntp Config
                                break;
                            case 'srstinfo':
                                if ($data_values['srst_Option'] == 'user') {
                                    break;
                                }
                                $xnode = &$xml_node->$dkey;
                                $xnode->name = $data_values['srst_Name'];
                                $xnode->srstOption = $data_values['srst_Option'];
                                $xnode->userModifiable = $data_values['srst_userModifiable'];
                                $xnode->isSecure = $data_values['srst_isSecure'];

                                // srst addresses are now stored as json;
                                $srst_addrs = $this->convertCsvToArray($data_values['srst_ip']);
                                //Now have an array of srst addresses - maybe empty

                                $srstSlot = 0;
                                foreach ($srst_addrs as $netValue) {
                                    // the template numbers these from one (ipAddr1..ipAddr3)
                                    $srstSlot++;
                                    if ($srstSlot > 3) {
                                        break;
                                    }
                                    $nodeName = "ipAddr{$srstSlot}";
                                    $xnode->$nodeName = $netValue['ip'];
                                    $nodeName = "port{$srstSlot}";
                                    $xnode->$nodeName = $netValue['port'];
                                }
                                break;
                            case 'connectionmonitorduration':
                                $xml_node->$dkey = strval(intval(intval($data_values['keepalive']) * 0.75));
                                break;
                            case 'callmanagergroup':
                                $xnode = &$xml_node->$dkey->members;
                                $bind_tmp = $this->get_server_sccp_bind($data_values);
                                $ifc = 0;
                                foreach ($bind_tmp as $bind_value) {
                                    $xnode_obj = clone $xnode->member;
                                    $xnode_obj['priority'] = $ifc;
                                    $xnode_obj->callManager->name = $data_values['servername'];
                                    if (!is_null($xnode_obj->callManager->description)) {
                                        $xnode_obj->callManager->description = $data_values['servername'];
                                    }
                                    $xnode_obj->callManager->ports->ethernetPhonePort = $bind_value['port'];
                                    $xnode_obj->callManager->processNodeName = $bind_value['ip'];
                                    if (!empty($xnode_obj->callManager->ports->mgcpPorts)) {
                                        unset($xnode_obj->callManager->ports->mgcpPorts);
                                    }

                                    if ($ifc === 0) {
                                        $this->replaceSimpleXmlNode($xnode->member, $xnode_obj);
                                    } else {
                                        $this->appendSimpleXmlNode($xnode->member, $xnode_obj);
                                    }
                                    $ifc++;
                                }
                        }
                    }
                    $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                    break;
                case 'vendorconfig':
                    $xml_node = $xml_work->$key;
                    foreach ($xml_work->$key->children() as $dkey => $ddata) {
                        if (isset($data_values[strtolower($dkey)])) {
                            $vtmp_data = $data_values[strtolower($dkey)];
                            if (!empty($vtmp_data)) {
                                switch ($vtmp_data) {
                                    case 'off':
                                        $xml_node->$dkey = 1;
                                        break;
                                    case 'on':
                                        $xml_node->$dkey = 0;
                                        break;
                                    default:
                                        $xml_node->$dkey = $vtmp_data;
                                        break;
                                }
                            }
                        }
                    }
                    $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                    break;

                case 'versionstamp':
                    $xml_work->$key = time();
                    break;
                case 'loadinformation':
                    if (isset($dev_config["tftp_firmware"])) {
                        $xml_work->$key = (isset($dev_config["loadimage"])) ? $dev_config["tftp_firmware"] . $dev_config["loadimage"] : '';
                    } else {
                        $xml_work->$key = (isset($dev_config["loadimage"])) ? $dev_config["loadimage"] : '';
                    }
                    // The expansion modules used to be written here. They are now added
                    // once, after this loop, by addAddOnModules(): see the note there.
                    break;
                case 'commonprofile':
                    // The phone reads this in clear text - that is how the Cisco provisioning
                    // protocol works - and the file is served over TFTP without authentication.
                    // So write it only when it actually holds something: an unset SSH password
                    // has no business appearing in the file at all.
                    // And clear it explicitly when it is empty: the node comes from the
                    // template, and a template already installed on a system (they are not
                    // refreshed on upgrade) may still carry the literal it used to ship with.
                    $xml_node->phonePassword = !empty($data_values['dev_sshPassword']) ? $data_values['dev_sshPassword'] : '';
                    $xml_node->backgroundImageAccess = (($data_values['backgroundImageAccess'] == 'on') || ($data_values['backgroundImageAccess'] == 'true') ) ? 'true' : 'false';
                    $xml_node->callLogBlfEnabled = $data_values['callLogBlfEnabled'];
                    break;

                case 'userlocale':
                    // Device language
                    $lang = $data_values['devlang'];
                    if (!empty($dev_config['devlang'])) {
                        $lang = $dev_config['devlang'];
                    }
                    $xml_node->winCharSet = $dev_config['phonecodepage'];
                    $xml_node->name = $dev_config['devlang'];
                    $xml_node->langCode = 'en';
                    if (isset($this->langCodeArray[$dev_config['devlang']])) {
                        $xml_node->langCode = $this->langCodeArray[$dev_config['devlang']];
                    }
                    $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                    break;
                case 'networklocale':
                    $lang = $data_values['netlang'];
                    if (!empty($dev_config['netlang'])) {
                        $lang = $dev_config['netlang'];
                    }
                    if (isset($lang)) {
                        $xml_work->$key = $lang;
                        $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                    } else {
                        $xml_work->$key = '';
                    }
                    break;
                case 'networklocaleinfo':
                    $lang = $data_values['netlang'];
                    if (!empty($dev_config['netlang'])) {
                        $lang = $dev_config['netlang'];
                    }
                    if (isset($lang)) {
                        $xml_node->name = $lang;
                        $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                    } else {
                        $xml_work->$key = '';
                    }
                    break;
                default:
                    break;
            }
        }

        // The loop above stopped writing the expansion modules when the block moved into
        // addAddOnModules(), but the call that was supposed to replace it only went into
        // the SIP path. A 7975 with a 7916 got a SEP file with no <addOnModules> at all -
        // the exact opposite of what the change set out to do.
        $this->addAddOnModules($xml_work, $dev_config);

        $this->saveXml($xml_work, $xml_name);  // Save

        return time();
    }

    private function get_server_sccp_bind($data_values = array())
    {
        $res = array();
        if (!empty($data_values['bindaddr']) && $data_values['bindaddr'] !== '0.0.0.0') {
            $rkey = $data_values['bindaddr'];
            $res[$rkey] = array('ip' => $data_values['bindaddr'], 'port' => $data_values['port']);
            return $res;
        }
        $ip_fill = true;
        if (!empty($data_values['ccm_address'])) {
            // ccm_address is json from sccpsettings
            $ccm_address = $this->convertCsvToArray($data_values['ccm_address'], true);
            // This is a sanitised list of ipaddresses and ports
            foreach ($ccm_address as $netValue) {
                $res[$netValue['ip']] = $netValue;
            }
        }
        if ($ip_fill) {
            foreach ($data_values['server_if_list'] as $value) {
                if (!empty($value['ip'])) {
                    if (!in_array($value['ip'], array('0.0.0.0', '127.0.0.1'), true)) {
                        $rkey = $value['ip'];
                        $res[$rkey] = array('ip' => $rkey, 'port' => $data_values['port']);
                    }
                }
            }
        }
        if (!empty($data_values['externhost'])) {
            $rkey = $data_values['externhost'];
            if (!in_array($rkey, array('0.0.0.0', '127.0.0.1', 'localhost'), true)) {
                $res[$rkey] = array('ip' => $rkey, 'port' => $data_values['port']);
            }
        } else {
            if (!empty($data_values['externip'])) {
                $rkey = $data_values['externip'];
                if (!in_array($rkey, array('0.0.0.0', '127.0.0.1'), true)) {
                    $res[$rkey] = array('ip' => $rkey, 'port' => $data_values['port']);
                }
            }
        }

        return $res;
    }

    function create_SEP_SIP_XML($store_path = '', $data_values = array(), $dev_config = array(), $dev_id = '', $lang_info = array())
    {
        $var_xml_general_fields = array('authenticationURL' => 'dev_authenticationURL', 'informationURL' => 'dev_informationURL', 'messagesURL' => 'dev_messagesURL',
            'servicesURL' => 'dev_servicesURL', 'directoryURL' => 'dev_directoryURL', 'proxyServerURL' => 'dev_proxyServerURL', 'idleTimeout' => 'dev_idleTimeout',
            'idleURL' => 'dev_idleURL', 'sshUserId' => 'dev_sshUserId', 'sshPassword' => 'dev_sshPassword',
            'phonePersonalization' => 'phonepersonalization'
        );
        $var_xml_sipProfile = array('phoneLabel' => 'description',
            'transferOnhookEnabled' => 'transferOnhookEnabled', 'enableVad' => 'enableVad', 'voipControlPort' => 'sipport'
        );
        $var_xml_sipline = array('name' => 'account', 'featureLabel' => 'account', 'displayName' => 'callerid', 'contact' => 'account',
            'authName' => 'account', 'authPassword' => 'secret');
        $var_xml_general_vars = array('capfAuthMode' => 'null', 'capfList' => 'null', 'mobility' => 'null',
            'phoneServices' => 'null', 'certHash' => 'null', 'deviceProtocol' => 'SIP',
            'deviceSecurityMode' => '1');

//        $var_hw_config = $this->dbinterface->getSccpDeviceTableData("get_sccpdevice_byid", array('id' => $dev_id));

        if (empty($dev_config)) {
            return false;
        }
        $data_path = $dev_config['tftp_path'];

        if (empty($store_path) || empty($data_path) || empty($data_values) || empty($dev_id)) {
            return;
        }

        if (!empty($dev_config['nametemplate'])) {
            $xml_template = $data_path . '/templates/' . basename((string) $dev_config['nametemplate']);   // keep the template inside the templates dir
            if (!file_exists($xml_template)) {
                $xml_template = $data_path . '/templates/SEP0000000000.cnf.xml_79df_sip_template';
            }
        } else {
            $xml_template = $data_path . '/templates/SEP0000000000.cnf.xml_79df_sip_template';
        }
        $xml_name = $store_path . '/' . $dev_id . '.cnf.xml';
        $sip_bind = $data_values['sbind'];
        $bind_proto = 'tcp';
        $bind_ip_def = '';
        foreach ($sip_bind as $key => $value) {
            if (empty($bind_ip_def)) {
                $bind_ip_def = $key;
                $bind_proto = (isset($value['tcp'])) ? 'tcp' : 'udp';
            }
        }
        if (file_exists($xml_template)) {
            $xml_work = simplexml_load_file($xml_template);

            foreach ($var_xml_general_vars as $key => $data) {
                if (isset($xml_work->$key)) {
                    if ($data != 'null') {
                        $xml_work->$key = $data;
                    } else {
                        $node = $xml_work->$key;
                        unset($node[0][0]);
                    }
                }
            }

            foreach ($xml_work as $key => $data) {
//              Set System global Values
                if (!empty($var_xml_general_fields[$key])) {
                    $xml_work->$key = $data_values[$var_xml_general_fields[$key]];
                }
//              Set section Values
                $xml_node = $xml_work->$key;
                switch ($key) {
                    case 'devicePool':
                        $xml_node = $xml_work->$key;
                        foreach ($xml_work->$key->children() as $dkey => $ddata) {
                            switch ($dkey) {
                                case 'dateTimeSetting':
                                    $xnode = &$xml_node->$dkey;
                                    $tz_id = $data_values['ntp_timezone'];
                                    $TZdata = $data_values['ntp_timezone_id'];
                                    if (empty($TZdata)) {
                                        $TZdata = array('offset' => '0', 'daylight' => '', 'cisco_code' => 'Greenwich Standard Time');
                                    }
                                    $xnode->dateTemplate = $data_values['dateformat'];
                                    $xnode->timeZone = $TZdata['cisco_code'];

                                    if ($data_values['ntp_config_enabled'] == 'on') {
                                        $xnode->ntps->ntp->name = $data_values['ntp_server'];
                                        $xnode->ntps->ntp->ntpMode = $data_values['ntp_server_mode'];
                                    } else {
                                        $xnode->ntps = null;
                                    }
                                    // Ntp Config
                                    break;
                                case 'callManagerGroup':
                                    $xnode = &$xml_node->$dkey->members;
                                    $ifc = 0;
                                    foreach ($sip_bind as $bind_ip => $bind_value) {
                                        $xnode_obj = clone $xnode->member;
                                        $xnode_obj['priority'] = $ifc;
                                        $xnode_obj->callManager->name = $data_values['servername'];
                                        $xnode_obj->callManager->ports->sipPort = $bind_value[$bind_proto];
//                                        $xnode_obj->callManager->ports->securedSipPort = $bind_value['tlsport'];
                                        $xnode_obj->callManager->processNodeName = $bind_ip;
                                        if ($ifc === 0) {
                                            $this->replaceSimpleXmlNode($xnode->member, $xnode_obj);
                                        } else {
                                            $this->appendSimpleXmlNode($xnode->member, $xnode_obj);
                                        }
                                        $ifc++;
                                    }
                            }
                        }
                        $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                        break;
                    case 'sipProfile':
                        $xml_node = $xml_work->$key;
                        foreach ($xml_work->$key->children() as $dkey => $ddata) {
                            if (!empty($var_xml_sipProfile[$dkey])) {
                                if (!empty($data_values[$var_xml_sipProfile[$dkey]])) {
                                    $xml_node->$dkey = $data_values[$var_xml_sipProfile[$dkey]];
                                } else {
                                    $xml_node->$dkey = null;
                                }
                            }
                            switch ($dkey) {
                                case 'sipProxies':
                                    $xnode = &$xml_node->$dkey;
                                    $xnode->backupProxy = $bind_ip_def;
                                    $xnode->backupProxyPort = $sip_bind[$bind_ip_def][$bind_proto];
                                    $xnode->emergencyProxy = $bind_proto;
                                    $xnode->emergencyProxyPort = $sip_bind[$bind_ip_def][$bind_proto];
                                    $xnode->outboundProxy = $bind_proto;
                                    $xnode->outboundProxyPort = $sip_bind[$bind_ip_def][$bind_proto];
                                    $xnode->registerWithProxy = "true";

                                    break;
                                case 'sipLines':
                                    $xnode = &$xml_node->$dkey;
                                    $ifc = 0;
                                    if (!empty($data_values['siplines'])) {
                                        foreach ($data_values['siplines'] as $spkey => $spvalue) {
//                                            if $spvalue[]
                                            $xnode_obj = clone $xnode->line;
                                            $xnode_obj['button'] = $ifc + 1;
                                            $xnode_obj['lineIndex'] = $ifc + 1;
                                            //$xnode_obj->proxy = $data_values['bindaddr'];
                                            $xnode_obj->featureID = "9";
                                            if ($xnode_obj->proxy != 'USECALLMANAGER') {
                                                $xnode_obj->proxy = $bind_proto;
                                                $xnode_obj->port = $sip_bind[$bind_ip_def][$bind_proto];
                                            }

                                            foreach ($var_xml_sipline as $line_key => $line_val) {
                                                $xnode_obj->$line_key = $spvalue[$line_val];
                                            }

                                            if ($ifc === 0) {
                                                $this->replaceSimpleXmlNode($xnode->line, $xnode_obj);
                                            } else {
                                                $this->appendSimpleXmlNode($xnode->line, $xnode_obj);
                                            }
                                            $ifc++;
                                        }
                                    }
                                    if (!empty($data_values['speeddial'])) {
                                        foreach ($data_values['speeddial'] as $spkey => $spvalue) {
                                            $xmlstr = '<line button="' . ($ifc + 1) . '"> <featureID>22</featureID>'
                                                    . '<featureLabel>' . htmlspecialchars($spvalue["name"], ENT_QUOTES | ENT_XML1) . '</featureLabel>'
                                                    . '<speedDialNumber>' . htmlspecialchars($spvalue["dial"], ENT_QUOTES | ENT_XML1) . '</speedDialNumber>'
                                                    . '<contact>' . htmlspecialchars($spvalue["dial"], ENT_QUOTES | ENT_XML1) . '</contact> <retrievalPrefix /></line>';
                                            $xnode_obj = simplexml_load_string($xmlstr);
                                            $this->appendSimpleXmlNode($xnode->line, $xnode_obj);
                                            $ifc++;
                                        }
                                    }
                                    break;
                                case 'softKeyFile':
                                case 'dialTemplate': // Доработать !
                                    $xml_ext_file = '';
                                    $template_path = (($dkey == 'softKeyFile') ? $dev_config['tftp_softkey_path'] : $dev_config['tftp_dialplan_path']);
                                    $tmp_key = ($dkey == 'softKeyFile') ? 'softkeyset' : '_dialrules';
                                    if (!empty($dev_config[$tmp_key])) {
                                        $xml_ext_file = (($dkey == 'softKeyFile') ? 'softkey' . $dev_config[$tmp_key] . '.xml' : $dev_config[$tmp_key] . '.xml');
                                    }
                                    if (empty($xml_ext_file) || !file_exists($template_path . '/' . $xml_ext_file)) {
                                        $xml_ext_file = (($dkey == 'softKeyFile') ? 'softkeydefault.xml' : 'dialplan.xml');
                                    }
                                    if (file_exists($template_path . '/' . $xml_ext_file)) {
                                        $xml_node->$dkey = $xml_ext_file;
                                    } else {
                                        $xml_node->$dkey = null;
                                    }
                                    break;
                            }
                        }
                        $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                        break;

                    case 'versionStamp':
                        $xml_work->$key = time();
                        break;
                    case 'loadInformation':
//                      Set Path Image ????
                        if (isset($dev_config["tftp_firmware"])) {
                            $xml_work->$key = (isset($dev_config["loadimage"])) ? $dev_config["tftp_firmware"] . $dev_config["loadimage"] : '';
                        } else {
                            $xml_work->$key = (isset($dev_config["loadimage"])) ? $dev_config["loadimage"] : '';
                        }
                        // Expansion modules are added once after this loop, by
                        // addAddOnModules(). Same reason as the SCCP branch.
                        break;
                    case 'commonProfile':
                        // see the SCCP branch above: only write a credential that is actually set
                        if (!empty($data_values['dev_sshPassword'])) {
                            $xml_node->phonePassword = $data_values['dev_sshPassword'];
                        }
                        $xml_node->backgroundImageAccess = (($data_values['backgroundImageAccess'] == 'on') || ($data_values['backgroundImageAccess'] == 'true') ) ? 'true' : 'false';
                        $xml_node->callLogBlfEnabled = $data_values['callLogBlfEnabled'];
                        break;
                    case 'userLocale':
                        // Device language
                        $lang = $data_values['devlang'];
                        if (!empty($dev_config['devlang'])) {
                            $lang = $dev_config['devlang'];
                        }
                        $xml_node->winCharSet = $dev_config['phonecodepage'];
                        $xml_node->name = $dev_config['devlang'];
                        $xml_node->langCode = 'en';
                        if (isset($this->langCodeArray[$lang])) {
                            $xml_node->langCode = $this->langCodeArray[$lang];
                        }
                        $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                        break;
                    case 'networkLocale':
                        $lang = $data_values['netlang'];
                        if (!empty($dev_config['netlang'])) {
                            $lang = $dev_config['netlang'];
                        }
                        if (isset($lang)) {
                            $xml_work->$key = $lang;
                            $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                        } else {
                            $xml_work->$key = '';
                        }
                        break;
                    case 'networkLocaleInfo':
                        $lang = $data_values['netlang'];
                        if (!empty($dev_config['netlang'])) {
                            $lang = $dev_config['netlang'];
                        }
                        if (isset($lang)) {
                            $xml_node->name = $lang;
                            $this->replaceSimpleXmlNode($xml_work->$key, $xml_node);
                        } else {
                            $xml_work->$key = '';
                        }
                        break;
                    default:
                        break;
                }
            }

            $this->addAddOnModules($xml_work, $dev_config);
            $this->saveXml($xml_work, $xml_name);  // Save
        } else {
            freepbx_log(FPBX_LOG_ERROR, 'sccp_manager: hardware template not found: ' . $xml_template);
            return false;
        }
        return time();
    }

    function saveDialPlan($confDir, $get_settings)
    {
        $xmlstr = "<DIALTEMPLATE>\n";
        $xmlstr .= "<versionStamp>" . time() . "</versionStamp>\n";
        $dialFelds = array('match', 'timeout', 'rewrite', 'tone'); //str -to lo !

        $hdr_prefix = 'sccp_dial_';
        $hdr_arprefix = 'sccp_dial-ar_';
        $save_data = array();
        $integer_msg = _("%s must be a non-negative integer");
        $errors = array();
        $dialTemplates = $get_settings[$hdr_arprefix . 'dialtemplate'] ?? null;
        if (!is_array($dialTemplates) || empty($dialTemplates)) {
            return array(_("At least one dial plan template line is required"));
        }
        foreach ($dialTemplates as $key => $value) {
            $xmlstr .= '<TEMPLATE';
            if (!empty($value['match'])) {
                foreach ($dialFelds as $fld) {
                    if (isset($value[$fld])) {
                        if ($value[$fld] == 'empty' || $value[$fld] == '') {

                        } else {
                            $xmlstr .= ' ' . $fld . '="' . htmlspecialchars((string) $value[$fld], ENT_QUOTES | ENT_XML1) . '"';
                        }
                    }
                }
            } else {
                $errors[] = _("Fields need to match !!");
            }
            $xmlstr .= "/>\n";
        }
        $xmlstr .= '</DIALTEMPLATE>';
        if (!empty($get_settings['idtemplate'])) {
            if ($get_settings['idtemplate'] == '*new*') {
                if (!empty($get_settings[$hdr_prefix . 'dialtemplate_name'])) {
                    $put_file = (string) $get_settings[$hdr_prefix . 'dialtemplate_name'];
                } else {
                    $errors = array('Fields Dial Plan Name is requered !!');
                }
            } else {
                $put_file = (string) $get_settings['idtemplate'];
            }
        } else {
            $errors = array('Fields Dial Plan Name is required !!');
        }

        if (empty($errors)) {
            $put_file = str_replace(array("\n", "\r", "\t", "/", "\\", ".", ","), '', $put_file);
            $file = $confDir . '/dial' . $put_file . '.xml';
            if (file_put_contents($file, $xmlstr) === false) {
                $errors[] = sprintf(_("Could not write %s"), $file);
            }
        }

        return $errors;
    }

    function create_xmlSoftkeyset($config, $confDir, $name)
    {
        if (empty($config[$name])) {
            if ($name == 'default') {
                $typeSoft = $confDir["tftp_templates_path"] . '/SIPDefaultSoftKey.xml_template';
                if (file_exists($typeSoft)) {
                    $file = $confDir["tftp_softkey_path"] . '/softkey' . basename($name) . '.xml';
                    if (!copy($typeSoft, $file)) {
                        return array('error' => 'Access error' . $name);
                    }
                }
                return array();
            } else {
                return array('error' => 'Invalid softkey Name' . $name);
            }
        }
        $errors = array();
        $xmlstr = "<softKeyCfg>\n";
        $xmlstr .= "<versionStamp>" . time() . "</versionStamp>\n";
        $typeSoft = $confDir["tftp_templates_path"] . '/SIPTypeSoftKey.xml_template';
        $read_soft = "";
        if (file_exists($typeSoft)) {
            $f_read = fopen($typeSoft, 'r');
            while (!feof($f_read)) {
                $read_soft .= fread($f_read, 8192);
            }
            fclose($f_read);
        }
        $xmlstr .= $read_soft;
        $xmlstr .= "  <softKeySets>\n";
        foreach ($config[$name] as $key => $value) {
            if ($key === 'type' || !is_string($value)) {
                continue;                                       // section meta, not a key set
            }
            $xmlstr .= '    <softKeySet id="' . htmlspecialchars($key, ENT_QUOTES | ENT_XML1) . '">' . "\n";
            foreach (explode(",", $value) as $keyvalue) {
                $xmlstr .= '      <softKey keyID="' . htmlspecialchars($keyvalue, ENT_QUOTES | ENT_XML1) . '" />' . "\n";
            }
            $xmlstr .= "    </softKeySet>\n";
        }
        $xmlstr .= "  </softKeySets>\n";

        $xmlstr .= '</softKeyCfg>';
        if (empty($errors)) {
            $file = $confDir["tftp_softkey_path"] . '/softkey' . basename($name) . '.xml';
            file_put_contents($file, $xmlstr);
        }

        return $errors;
    }

    private function replaceSimpleXmlNode(\SimpleXMLElement $xml, \SimpleXMLElement $element)
    {
        $dom = dom_import_simplexml($xml);
        $import = $dom->ownerDocument->importNode(
            dom_import_simplexml($element),
            true
        );
        $dom->parentNode->replaceChild($import, $dom);
    }

    private function appendSimpleXmlNode(\SimpleXMLElement $xml, \SimpleXMLElement $element)
    {

        $dom = dom_import_simplexml($xml);
        $import = $dom->ownerDocument->importNode(
            dom_import_simplexml($element),
            true
        );
        $dom->parentNode->appendChild($import->cloneNode(true));
    }
}
