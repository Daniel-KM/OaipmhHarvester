<?php

class OaipmhHarvester_Request
{
    /**
     * OAI-PMH error code for a repository with no set hierarchy
     */
    const OAI_ERR_NO_SET_HIERARCHY = 'noSetHierarchy';

    /**
     * @var string
     */
    private $_baseUrl;

    /**
     * @var Zend_Http_Client
     */
    private $_client;

    /**
     * Constructor.
     *
     * @param string $baseUrl
     */
    public function __construct($baseUrl = null)
    {
        if ($baseUrl) {
            $this->setBaseUrl($baseUrl);
        }
    }

    public function setBaseUrl($baseUrl)
    {
        $this->_baseUrl = $baseUrl;
    }

    /**
     * List metadata response formats for the provider.
     *
     * @return array Keyed array, where key is the metadataPrefix and value
     * is the schema.
     */
    public function listMetadataFormats()
    {
        $xml = $this->_makeRequest(array(
            'verb' => 'ListMetadataFormats',
        ));

        $formats = array();
        $error = $this->_getError($xml);
        if ($error) {
            $formats['error'] = $error;
        }

        foreach ($xml->ListMetadataFormats->metadataFormat as $format) {
            $prefix = trim((string) $format->metadataPrefix);
            $schema = trim((string) $format->schema);
            $formats[$prefix] = $schema;
        }
        /*
         * It's important to consider that some repositories don't provide
         * repository-wide metadata formats. Instead they only provide record
         * level metadata formats. Oai_dc is mandatory for all records, so if a
         * repository doesn't provide metadata formats using ListMetadataFormats,
         * only expose the oai_dc prefix. For a data provider that doesn't offer
         * repository-wide metadata formats, see:
         * http://www.informatik.uni-stuttgart.de/cgi-bin/OAI/OAI.pl
         */
        if (empty($formats)) {
            $formats[OaipmhHarvester_Harvest_OaiDc::METADATA_PREFIX] =
                OaipmhHarvester_Harvest_OaiDc::METADATA_SCHEMA;
        }
        return $formats;
    }

    /**
     * List all records for a given request.
     *
     * @param array $query Args may include: metadataPrefix, set,
     * resumptionToken, from.
     */
    public function listRecords(array $query = array())
    {
        $query['verb'] = 'ListRecords';
        $xml = $this->_makeRequest($query);
        $response = array(
            'records' => $xml->ListRecords->record,
        );
        $error = $this->_getError($xml);
        if ($error) {
            // Try another way.
            if ($query) {
                $url = $this->_baseUrl . '?' . http_build_query($query, null, '&', PHP_QUERY_RFC3986);
                $curl = curl_init($url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $output = curl_exec($curl);
                curl_close($curl);
                if ($output) {
                    $xml = $output;
                    libxml_use_internal_errors(true);
                    $iter = simplexml_load_string($xml);
                    if ($iter !== false) {
                        $ns = $iter->getNamespaces();
                        $ns_key = array_search("http://www.openarchives.org/OAI/2.0/", $ns);
                        if ($ns_key !== false and $ns_key !== "") {
                            $iter = simplexml_load_string(
                                $xml,
                                "SimpleXMLElement",
                                0,
                                $ns_key,
                                true
                            );
                        }
                    }
                    if ($iter === false) {
                        $errors = array();
                        foreach (libxml_get_errors() as $error) {
                            $errors[] = trim($error->message) . ' on line '
                                . $error->line . ', column '
                                . $error->column;
                        }
                        libxml_clear_errors();
                        libxml_use_internal_errors(false);
                        _log(
                            "[OaipmhHarvester] Could not parse XML: "
                            . $xml
                        );
                        $errStr = join("\n", $errors);
                        _log("[OaipmhHarvester] XML errors in document: " . $errStr);
                        throw new Zend_Http_Client_Exception(
                            "Error in parsing response XML. XML document had the "
                            . "following errors: \n"
                            . $errStr
                        );
                    }
                    $xml = $iter;
                    $response = array(
                        'records' => $xml->ListRecords->record,
                    );
                    $error = $this->_getError($xml);
                    if ($error) {
                        $response['error'] = $error;
                    }
                } else {
                    $response['error'] = $error;
                }
            } else {
                $response['error'] = $error;
            }
        }
        $token = $xml->ListRecords->resumptionToken;
        if ($token) {
            $response['resumptionToken'] = (string) $token;
        }
        return $response;
    }

    /**
     * List all available sets from the provider.
     *
     * Resumption token can be given for incomplete lists.
     *
     * @param string|null $resumptionToken
     */
    public function listSets($resumptionToken = null)
    {
        $query = array(
            'verb' => 'ListSets',
        );
        if ($resumptionToken) {
            $query['resumptionToken'] = $resumptionToken;
        }

        $retVal = array();
        $sets = array();
        try {
            $xml = $this->_makeRequest($query);

            // Handle returned errors, such as "noSetHierarchy". For a data
            // provider that has no set hierarchy, see:
            // http://solarphysics.livingreviews.org/register/oai
            $error = $this->_getError($xml);
            if ($error) {
                $retVal['error'] = $error;
                if ($error['code'] ==
                        self::OAI_ERR_NO_SET_HIERARCHY
                ) {
                    $sets = array();
                }
            } else {
                $sets = $xml->ListSets->set;
            }
            if (isset($xml->ListSets->resumptionToken)) {
                $retVal['resumptionToken'] = $xml->ListSets->resumptionToken;
            }
        } catch (Exception $e) {
            // If we're here, the provider didn't even respond with valid XML.
            // Try to continue with no sets.
            $sets = array();
        }

        $retVal['sets'] = $sets;
        return $retVal;
    }

    public function getClient()
    {
        if ($this->_client === null) {
            $this->setClient();
        }
        return $this->_client;
    }

    public function setClient(Zend_Http_Client $client = null)
    {
        if ($client === null) {
            $client = new Omeka_Http_Client();

            $config = array();

            $timeout = get_option('oaipmhharvester_http_client_timeout');
            if (isset($timeout) && is_numeric($timeout) && $timeout > 0) {
                $config['timeout'] = $timeout;
            }

            $client->setConfig($config);
        }
        $this->_client = $client;
    }

    private function _getError($xml)
    {
        $error = array();
        if ($xml->error) {
            $error['message'] = (string) $xml->error;
            $error['code'] = $xml->error->attributes()->code;
        }
        return $error;
    }

    private function _makeRequest(array $query)
    {
        $client = $this->getClient();
        $client->setUri($this->_baseUrl);
        $client->setConfig(
            array(
                'useragent' => $this->_getUserAgent(),
            )
        );
        $client->setParameterGet($query);
        $response = $client->request('GET');
        if ($response->isSuccessful() && !$response->isRedirect()) {
            libxml_use_internal_errors(true);
            $iter = simplexml_load_string($response->getBody());
            if ($iter !== false) {
                $ns = $iter->getNamespaces();
                $ns_key = array_search("http://www.openarchives.org/OAI/2.0/", $ns);
                if ($ns_key !== false and $ns_key !== "") {
                    $iter = simplexml_load_string(
                        $response->getBody(),
                        "SimpleXMLElement",
                        0,
                        $ns_key,
                        true
                    );
                }
            }
            if ($iter === false) {
                $errors = array();
                foreach (libxml_get_errors() as $error) {
                    $errors[] = trim($error->message) . ' on line '
                              . $error->line . ', column '
                              . $error->column;
                }
                libxml_clear_errors();
                libxml_use_internal_errors(false);
                _log(
                    "[OaipmhHarvester] Could not parse XML: "
                    . $response->getBody()
                );
                $errStr = join("\n", $errors);
                _log("[OaipmhHarvester] XML errors in document: " . $errStr);
                throw new Zend_Http_Client_Exception(
                    "Error in parsing response XML. XML document had the "
                    . "following errors: \n"
                    . $errStr
                );
            }
            return $iter;
        } else {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            throw new Zend_Http_Client_Exception("Invalid URL ("
                . $response->getStatus() . " " . $response->getMessage()
                . ").");
        }
    }

    private function _getUserAgent()
    {
        try {
            $version = get_plugin_ini('OaipmhHarvester', 'version');
        } catch (Zend_Exception $e) {
            $version = '';
        }
        return 'Omeka OAI-PMH Harvester/' . $version;
    }
}
