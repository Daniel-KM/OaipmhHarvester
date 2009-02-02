<?php
abstract class Oaipmh_Harvest_Abstract
{
    const MESSAGE_CODE_NOTICE = 1;
    const MESSAGE_CODE_ERROR = 2;
    
    private $_set;
    
    // The current, cached Oaipmh_Xml object.
    private $_oaipmhXml;
    
    // The current, cached SimpleXML record object.
    private $_record;
    
    public function __construct($set)
    {        
        $this->_set = $set;
        
        try {
            // Mark the set as in progress.
            $this->_set->status = OaipmhHarvesterSet::STATUS_IN_PROGRESS;
            $this->_set->save();
            
            // Call the template method that runs before the harvest.
            $this->beforeHarvest();
            // Initiate the harvest.
            $this->_harvest();
            // Call the template method that runs after the harvest.
            $this->afterHarvest();
            
            // Mark the set as completed.
            $this->_set->status    = OaipmhHarvesterSet::STATUS_COMPLETED;
            $this->_set->completed = $this->_getCurrentDateTime();
            $this->_set->save();
            
        } catch (Exception $e) {
            // Record the error.
            $this->addStatusMessage($e->getMessage(), self::MESSAGE_CODE_ERROR);
            $this->_set->status = OaipmhHarvesterSet::STATUS_ERROR;
            $this->_set->save();
        }
    }
    
    abstract protected function harvestRecord($record);
    
    private function _harvest($resumptionToken = false)
    {
        
        // Get the base URL.
        $baseUrl = $this->_set->base_url;
        
        // Set the request arguments.
        $requestArguments = array('verb' => 'ListRecords');
        if ($resumptionToken) {
            $requestArguments['resumptionToken'] = $resumptionToken;
        } else {
            $requestArguments['set']            = $this->_set->set_spec;
            $requestArguments['metadataPrefix'] = $this->_set->metadata_prefix;
        }
        
        // Cache the Oaipmh_Xml object.
        $this->_oaipmhXml = new Oaipmh_Xml($baseUrl, $requestArguments);
        
        // Throw an error if the response is an error.
        if ($this->_oaipmhXml->isError()) {
            $errorCode = (string) $this->_oaipmhXml->getErrorCode();
            $error     = (string) $this->_oaipmhXml->getError();
            $statusMessage = "$errorCode: $error";
            throw new Exception($statusMessage);
        }

        // Iterate through the records and hand off the mapping to the classes 
        // inheriting from this class.
        foreach ($this->_getRecords() as $record) {
            // Cache the record for later use.
            $this->_record = $record;
            $this->harvestRecord($record);
        }
        
        // If there is a resumption token, recurse this method.
        if ($resumptionToken = $this->_getResumptionToken()) {
            $this->_harvest($resumptionToken);
        }
        
        // If there is no resumption token, we're all done here.
        return;
    }
    
    private function _insertRecord($item)
    {
        $record = new OaipmhHarvesterRecord;
        
        $record->set_id     = $this->_set->id;
        $record->item_id    = $item->id;
        $record->identifier = (string) $this->_record->header->identifier;
        $record->datestamp  = (string) $this->_record->header->datestamp;
        $record->save();
    }
    
    private function _getRecords()
    {
        return $this->_oaipmhXml->getOaipmh()->ListRecords->record;
    }
    
    private function _getResumptionToken()
    {
        $oaipmh = $this->_oaipmhXml->getOaipmh();
        if (isset($oaipmh->ListRecords->resumptionToken)) {
            $resumptionToken = (string) $oaipmh->ListRecords->resumptionToken;
            if (!empty($resumptionToken)) {
                return $resumptionToken;
            }
        }
        return false;
    }
    
    private function _getMessageCodeText($messageCode)
    {
        switch ($messageCode) {
            case self::MESSAGE_CODE_ERROR:
                $messageCodeText = 'Error';
                break;
            case self::MESSAGE_CODE_NOTICE:
            default:
                $messageCodeText = 'Notice';
                break;
        }
        return $messageCodeText;
    }
    
    private function _getCurrentDateTime()
    {
        return date('Y-m-d H:i:s');
    }
    
    protected function beforeHarvest()
    {
    }
    
    protected function afterHarvest()
    {
    }
    
    // Insert a collection.
    protected function insertCollection($metadata = array())
    {
        $collection = insert_collection($metadata);
        
        // Remember to set the set's collection ID once it has been saved.
        $this->_set->collection_id = $collection->id;
        $this->_set->save();
        
        return $collection;
    }
    
    // Insert an item.
    protected function insertItem($metadata = array(), $elementTexts = array())
    {
        $item = insert_item($metadata, $elementTexts);
        
        // Insert the record after the item is saved. The idea here is that the 
        // OaipmhHarvesterRecords table should only contain records that have 
        // corresponding items.
        $this->_insertRecord($item);
        
        return $item;
    }
    
    protected function addStatusMessage($message, $messageCode = null, $delimiter = "\n\n")
    {
        if (0 == strlen($this->_set->status_messages)) {
            $delimiter = '';
        }
        $date = $this->_getCurrentDateTime();
        $messageCodeText = $this->_getMessageCodeText($messageCode);
        
        $this->_set->status_messages = "{$this->_set->status_messages}$delimiter$messageCodeText: $message ($date)";
        $this->_set->save();
    }
    
    protected function getSet()
    {
        return $this->_set;
    }
}