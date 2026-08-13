<?php

/**
 *
 * Core Comsnd Interface
 *
 *  https://www.voip-info.org/asterisk-manager-example-php/
 */
/* !TODO!: Re-Indent this file.  -TODO-: What do you mean? coreaccessinterface  ??  */

namespace FreePBX\modules\Sccp_manager\aminterface;

// ************************************************************************** Event  *********************************************

#[\AllowDynamicProperties]
abstract class Event extends IncomingMessage
{

    protected $_events;

    public function getName()
    {
        return $this->getKey('Event');
    }

    public function __construct($rawContent)
    {
        parent::__construct($rawContent);
        $this->_events = array();
        $this->_completed = false;
    }
}

#[\AllowDynamicProperties]
class UnknownEvent extends Event
{
    public function __construct($rawContent = '')
    {
    }
}

#[\AllowDynamicProperties]
class TableStart_Event extends Event
{

    public function getTableName()
    {
        return $this->getKey('TableName');
    }
}

#[\AllowDynamicProperties]
class TableEnd_Event extends Event
{

    public function getTableName()
    {
        return $this->getKey('TableName');
    }
}

#[\AllowDynamicProperties]
class SCCPSoftKeySetEntry_Event extends Event
{
    // This is a list of tables, each table is an entry
    protected $_data;
}

#[\AllowDynamicProperties]
class ExtensionStatus_Event extends Event
{
    // this is a list of tables, each table is an entry
    public function getPrivilege()
    {
        return $this->getKey('Privilege');
    }

    public function getExtension()
    {
        return $this->getKey('Exten');
    }

    public function getContext()
    {
        return $this->getKey('Context');
    }

    public function getHint()
    {
        return $this->getKey('Hint');
    }

    public function getStatus()
    {
        return $this->getKey('Status');
    }
}

#[\AllowDynamicProperties]
class SCCPDeviceEntry_Event extends Event
{
    // This is a list of tables, each table is an entry
}

#[\AllowDynamicProperties]
class SCCPShowDevice_Event extends Event
{
    // This is a list of tables
    public function getCapabilities()
    {
        // TODO unused method - to be deleted?
        $ret = array();
        $codecs = explode(';', substr($this->getKey('Capabilities'), 1, -1));
        foreach ($codecs as $codec) {
            $codec_parts = explode(" ", $codec);
            $ret[] = array("name" => $codec_parts[0], "value" => substr($codec_parts[1], 1, -1));
        }
        return $ret;
    }

    public function getCodecsPreference()
    {
        // TODO unused method - to be deleted?
        $ret = array();
        $codecs = explode(';', substr($this->getKey('CodecsPreference'), 1, -1));
        foreach ($codecs as $codec) {
            $codec_parts = explode(" ", $codec);
            $ret[] = array("name" => $codec_parts[0], "value" => substr($codec_parts[1], 1, -1));
        }
        return $ret;
    }
}
#[\AllowDynamicProperties]
class SCCPDeviceButtonEntry_Event extends Event
{
}
#[\AllowDynamicProperties]
class SCCPDeviceFeatureEntry_Event extends Event
{
// Returned by SCCPShowDevice
}
#[\AllowDynamicProperties]
class SCCPVariableEntry_Event extends Event
{
// Returned by SCCPShowDevice
}
#[\AllowDynamicProperties]
class SCCPDeviceLineEntry_Event extends Event
{
}
#[\AllowDynamicProperties]
class SCCPDeviceStatisticsEntry_Event extends Event
{
}
#[\AllowDynamicProperties]
class SCCPDeviceSpeeddialEntry_Event extends Event
{
}
#[\AllowDynamicProperties]
abstract class ClosingEvent extends Event
{
      public function __construct($message) {
          parent::__construct($message);
          $this->_completed = true;
    }
    public function getListItems() {
        return intval($this->getKey('ListItems'));
    }

}
#[\AllowDynamicProperties]
class ResponseComplete_Event extends ClosingEvent
{
    // dummy event to avoid unnecessary testing
    public function listCorrectlyReceived($_message, $_eventCount){
        return true;
    }
}

#[\AllowDynamicProperties]
class SCCPShowDeviceComplete_Event extends ClosingEvent
{
    public function listCorrectlyReceived($_message, $_eventCount){
        // Have end of list event. Check with number of lines received and send true if match.
        // Remove 9 for the start and end events, and then 4.
        if ($this->getKey('listitems') === substr_count( $_message, "\n") -13) {
            return true;
        }
        return false;
    }
}
#[\AllowDynamicProperties]
class SCCPShowDevicesComplete_Event extends ClosingEvent
{
    public function listCorrectlyReceived($_message, $_eventCount) {
        // Have end of list event. Check with number of events received and send true if match.
        // Remove 9 for the lines in the list start and end, and the 2 blank lines.
        if ($this->getKey('listitems') === substr_count( $_message, "\n") -11) {
            return true;
        }
        return false;
    }
}
#[\AllowDynamicProperties]
class ExtensionStateListComplete_Event extends ClosingEvent
{
    public function listCorrectlyReceived($_message, $_eventCount){
        // Have end of list event. Check with number of events received and send true if match.
        // Remove 1 as the closing event is included in the count.
        if ($this->getKey('listitems') === $_eventCount -1) {
            return true;
        }
        return false;
    }
}

#[\AllowDynamicProperties]
class SCCPShowSoftKeySetsComplete_Event extends ClosingEvent
{
    public function listCorrectlyReceived($_message, $_eventCount){
        // Have the end of list event. Check the number of lines received and
        // return true if match. Remove 8 for the complete event.
        if ($this->getKey('listitems') === substr_count( $_message, "\n") -11) {
            return true;
        }
        return false;
    }
}
