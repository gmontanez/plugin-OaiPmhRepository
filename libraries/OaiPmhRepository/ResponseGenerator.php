<?php
/**
 * @package OaiPmhRepository
 * @author John Flatness, Yu-Hsun Lin
 */

require_once('Error.php');
require_once('OaiIdentifier.php');
require_once('UtcDateTime.php');
require_once('XmlUtilities.php');
require_once('Metadata/OaiDc.php');

// Namespace URIs for XML response document
define('OAI_PMH_NAMESPACE_URI', 'http://www.openarchives.org/OAI/2.0/');

// XML Schema URIs for XML response document 
define('OAI_PMH_SCHEMA_URI', 'http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');

// Calculated base URL for the repository.
define('BASE_URL', 'http://'.$_SERVER['SERVER_NAME'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Version of OAI-PMH protocol the repository plugin complies with.
define('PROTOCOL_VERSION', '2.0');

/**
 * OaiPmhRepository_ResponseGenerator generates the XML responses to OAI-PMH
 * requests recieved by the repository.  The DOM extension is used to generate
 * all the XML output on-the-fly.
 *
 * @package OaiPmhRepository
 * @todo Refactor out code for setting arguments in request element
 */
class OaiPmhRepository_ResponseGenerator
{
    public $responseDoc;
    private $request;
    public $error;

    /**
     * Constructor
     *
     * Creates the DomDocument object, and adds XML elements common to all
     * OAI-PMH responses.
     */
    public function __construct()
    {
        $this->error = false;
        $this->responseDoc = new DomDocument('1.0', 'UTF-8');
        //formatOutput makes DOM output "pretty" XML.  Good for debugging, but
        //adds some overhead, especially on large outputs
        $this->responseDoc->formatOutput = true;
        $this->responseDoc->xmlStandalone = true;
        
        $root = $this->responseDoc->createElementNS(OAI_PMH_NAMESPACE_URI,
            'OAI-PMH');
        $this->responseDoc->appendChild($root);
    
        $root->setAttributeNS(XML_SCHEMA_NAMESPACE_URI, 'xsi:schemaLocation',
            OAI_PMH_NAMESPACE_URI.' '.OAI_PMH_SCHEMA_URI);
    
        $responseDate = $this->responseDoc->createElement('responseDate', 
            OaiPmhRepository_UtcDateTime::currentTime());
        $root->appendChild($responseDate);

        $this->request = $this->responseDoc->createElement('request', BASE_URL);
        $OAI_PMH_SCHEMA_URI = 'http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd';
        $root->appendChild($this->request);
    }
    
    /**
     * Responds to the Identify verb.
     *
     * Appends the Identify element for the repository to the response.
     */
    public function identify()
    {
        if($this->error)
            return;
        $this->request->setAttribute('verb', 'Identify');
        /* according to the schema, this order of elements is required for the
         * response to validate
         */
        $elements = array( 
            'repositoryName'    => get_option('oaipmh_repository_name'),
            'baseURL'           => BASE_URL,
            'protocolVersion'   => PROTOCOL_VERSION,
            'adminEmail'        => get_option('administrator_email'),
            'earliestDatestamp' => OaiPmhRepository_UtcDateTime::convertToUtcDateTime(0),
            'deletedRecord'     => 'no',
            'granularity'       => 'YYYY-MM-DDThh:mm:ssZ');
        $identify = OaiPmhRepository_XmlUtilities::createElementWithChildren(
            $this->responseDoc->documentElement, 'Identify', $elements);
        
        $description = $this->responseDoc->createElement('description');
        $identify->appendChild($description);
        
        OaiPmhRepository_OaiIdentifier::describeIdentifier($description);
    }
    
    /**
     * Responds to the GetRecord verb.
     *
     * Outputs the header and metadata in the specified format for the specified
     * identifier.
     *
     * @param string identifier The oai-identifier for the desired record.
     * @param string metadataPrefix Code for the desired metadata format.
     */
    public function getRecord($identifier, $metadataPrefix)
    {
        $this->request->setAttribute('verb', 'GetRecord');
        if($identifier)
            $this->request->setAttribute('identifier', $identifier);
        if($metadataPrefix)
            $this->request->setAttribute('metadataPrefix', $metadataPrefix);
        
        $itemId = OaiPmhRepository_OaiIdentifier::oaiIdToItem($identifier);
        
        if(!$itemId) {
            OaiPmhRepository_Error::throwError($this, OAI_ERR_ID_DOES_NOT_EXIST);
            return;
        }
        
        $item = get_db()->getTable('Item')->find($itemId);

        if(!$item) {
            OaiPmhRepository_Error::throwError($this, OAI_ERR_ID_DOES_NOT_EXIST);
        }
        if($metadataPrefix != 'oai_dc') {
            OaiPmhRepository_Error::throwError($this, OAI_ERR_CANNOT_DISSEMINATE_FORMAT);
        }
        if(!$this->error) {
            $getRecord = $this->responseDoc->createElement('GetRecord');
            $this->responseDoc->documentElement->appendChild($getRecord);
            $record = new OaiPmhRepository_Metadata_OaiDc($item, $getRecord);
            $record->appendRecord();
        }
    }
    
    /**
     * Responds to the ListRecords verb.
     *
     * Outputs records for all of the items in the database in the specified
     * metadata format.
     *
     * @param string metadataPrefix Code for the desired metadata format.
     */
    public function listRecords($metadataPrefix, $set = null)
    {
        $this->request->setAttribute('verb', 'ListRecords');
        if($metadataPrefix)
            $this->request->setAttribute('metadataPrefix', $metadataPrefix);
        
        if($metadataPrefix != 'oai_dc')
            OaiPmhRepository_Error::throwError($this, OAI_ERR_CANNOT_DISSEMINATE_FORMAT);
        
        else {
            // will likely need to be replaced with some type of Zend_Db_Select or other more complex query
            if($set)
                $items = get_db()->getTable('Item')->findBy(array('collection' => $set));
            else
                $items = get_db()->getTable('Item')->findAll();
            
            if(count($items) == 0)
                OaiPmhRepository_Error::throwError($this, OAI_ERR_NO_ITEMS_MATCH);
        }
        
        if(!$this->error) {
            $listRecords = $this->responseDoc->createElement('ListRecords');
            $this->responseDoc->documentElement->appendChild($listRecords);
            foreach($items as $item) {
                $record = new OaiPmhRepository_Metadata_OaiDc($item, $listRecords);
                $record->appendRecord();
            }
        }
    }
    
    /**
     * Responds to the ListIdentifiers verb.
     *
     * Outputs headers for all of the items in the database in the specified
     * metadata format.
     *
     * @param string metadataPrefix Code for the desired metadata format.
     */
    public function listIdentifiers($metadataPrefix, $set = null)
    {
        $this->request->setAttribute('verb', 'ListIdentifiers');
        if($metadataPrefix)
            $this->request->setAttribute('metadataPrefix', $metadataPrefix);
        
        if($metadataPrefix != 'oai_dc') {
            OaiPmhRepository_Error::throwError($this, OAI_ERR_CANNOT_DISSEMINATE_FORMAT);
        }
        
        else {
            // will likely need to be replaced with some type of Zend_Db_Select or other more complex query
            if($set)
                $items = get_db()->getTable('Item')->findBy(array('collection' => $set));
            else
                $items = get_db()->getTable('Item')->findAll();
            
            if(count($items) == 0)
                OaiPmhRepository_Error::throwError($this, OAI_ERR_NO_ITEMS_MATCH);
        }
        
        if(!$this->error) {
            $listIdentifiers = $this->responseDoc->createElement('ListIdentifiers');
            $this->responseDoc->documentElement->appendChild($listIdentifiers);
            foreach($items as $item) {
                $record = new OaiPmhRepository_Metadata_OaiDc($item, $listIdentifiers);
                $record->appendHeader();
            }
        }
    }
    /**
     * Responds to the ListMetadataFormats verb.
     *
     * Outputs records for all of the items in the database in the specified
     * metadata format.
     *
     * @param string identifier OAI identifier for the desired record, if any.
     */
    public function listMetadataFormats($identifier = null)
    {
        $this->request->setAttribute('verb', 'ListMetadataFormats');
        if($identifier)
            $this->request->setAttribute('identifier', $identifier);
        if(!$this->error) {
            $listMetadataFormats = $this->responseDoc->createElement('ListMetadataFormats');
            $this->responseDoc->documentElement->appendChild($listMetadataFormats);
            $format = new OaiPmhRepository_Metadata_OaiDc(null, $listMetadataFormats);
            $format->declareMetadataFormat();
        }
    }

    /**
     * This function will output XML listing all the sets in the repository.
     */
    public function listSets()
    {
        $this->request->setAttribute('verb', 'listSets');
        
        $collectionObject = get_db()->getTable('Collection')->findBy();
        
        $setHeader = $this->responseDoc->createElement('ListSets');
        $this->responseDoc->documentElement->appendChild($setHeader); 
        
        if(count($collectionObject) == 0)
            OaiPmhRepository_Error::throwError($this, OAI_ERR_NO_SET_HIERARCHY);

        if(!$this->error) {
            foreach ($collectionObject as &$dummy) {
                $setBullet = $this->responseDoc->createElement('set');
                $setSpec = $this->responseDoc->createElement('setSpec', ($dummy->id));
                $setName = $this->responseDoc->createElement('setName', ($dummy->name));
                $setHeader->appendChild($setBullet);
                $setBullet->appendChild($setSpec);
                $setBullet->appendChild($setName);
            }
        unset($dummy);
        }
    }

    /**
     * Outputs the XML response as a string
     *
     * Called once processing is complete to obtain the XML to return to the client.
     
     * @return string the response XML
     */
    public function __toString()
    {
        return $this->responseDoc->saveXML();
    }
}
?>
