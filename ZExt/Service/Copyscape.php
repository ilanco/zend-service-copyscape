<?php

/**
 * Zend ZExt Framework
 *
 * @category   ZExt
 * @package    ZExt_Service
 * @subpackage Copyscape
 * @version    $Id: Copyscape.php 20101 2010-05-18 02:05:09Z ilanco $
 */


/**
 * @see Zend_Http_Client
 */
require_once 'Zend/Http/Client.php';


/**
 * @category   ZExt
 * @package    ZExt_Service
 * @subpackage Copyscape
 */
class ZExt_Service_Copyscape
{
    const API_URI = 'http://www.copyscape.com/api/';

    const OPER_URL_SEARCH = 'csearch';
    const OPER_CHECK_BALANCE = 'balance';



    /**
     * Zend_Http_Client Object
     *
     * @var     Zend_Http_Client
     * @access  protected
     */
    protected $_client;

    /**
     * Microtime of last request
     *
     * @var float
     */
    protected static $_lastRequestTime = 0;

    /**
     * Username
     *
     * @var string
     */
    protected $_authUsername;

    /**
     * API Key
     *
     * @var string
     */
    protected $_authApiKey;


    /**
     * Constructs a new copyscape object
     *
     * @param  string $username Client username
     * @param  string $apiKey  Client API key
     * @return void
     */
    public function __construct($username, $apiKey)
    {
        iconv_set_encoding('output_encoding', 'UTF-8');
        iconv_set_encoding('input_encoding', 'UTF-8');
        iconv_set_encoding('internal_encoding', 'UTF-8');

        $this->setAuth($username, $apiKey);
    }

    /**
     * Set client username and password
     *
     * @param  string $username Client user name
     * @param  string $apiKey  Client API key
     * @return ZExt_Service_Copyscape Provides a fluent interface
     */
    public function setAuth($username, $apiKey)
    {
        $this->_authUsername = $username;
        $this->_authApiKey = $apiKey;

        return $this;
    }

    /**
     * Set Http Client
     *
     * @param Zend_Http_Client $client
     */
    public function setHttpClient(Zend_Http_Client $client)
    {
        $this->_client = $client;
    }

    /**
     * Get current http client.
     *
     * @return Zend_Http_Client
     */
    public function getHttpClient()
    {
        if($this->_client == null) {
            $this->lazyLoadHttpClient();
        }
        return $this->_client;
    }

    /**
     * Lazy load Http Client if none is instantiated yet.
     *
     * @return void
     */
    protected function lazyLoadHttpClient()
    {
        $this->_client = new Zend_Http_Client();
    }


    /**
     * Check balance
     *
     * @param  string $url            URL to check
     * @param  string $fullComparison Request a full text-on-text comparison
     * @return mixed
     */
    public function checkBalance()
    {
        $response = $this->makeRequest(self::OPER_CHECK_BALANCE);

        return $this->_parseXmlResponse($response);
    }

    /**
     * URL search
     *
     * @param  string $url            URL to check
     * @param  string $fullComparison Request a full text-on-text comparison
     * @return mixed
     */
    public function urlSearch($url, $fullComparison = null)
    {
        $params = array();
        $params['q'] = $url;

        if (isset($fullComparison)) {
            $params['c'] = $fullComparison;
        }

        $response = $this->makeRequest(self::OPER_URL_SEARCH, $params);

        return $this->_parseXmlResponse($response);
    }

    /**
     * Text search
     *
     * @param  string $text            Text to check
     * @param  string $encoding        IANA character set name
     *                                 (http://www.iana.org/assignments/character-sets)
     * @param  string $fullComparison  Request a full text-on-text comparison
     * @return mixed
     */
    public function textSearch($text, $encoding = 'UTF-8', $fullComparison = null)
    {
        $getParams = array();
        $getParams['e'] = $encoding;

        if (isset($fullComparison)) {
            $getParams['c'] = $fullComparison;
        }

        $postParams = array();
        $postParams['t'] = $text;

        $response = $this->makeRequest(self::OPER_URL_SEARCH, $getParams, $postParams);

        return $this->_parseXmlResponse($response);
    }

    /**
     * Handles all requests to a web service
     *
     * @param   string $operation  Operation
     * @param   array  $getParams Array of GET parameters
     * @param   array  $postParams Array of POST parameters
     * @param   string $type  Type of a request ("xml"|"html")
     * @return  mixed  decoded response from web service
     * @throws  Zend_Service_Exception
     */
    protected function makeRequest($operation, array $getParams = array(), array $postParams = array(), $type = 'xml')
    {
        // If previous request was made less then 1 sec ago
        // wait until we can make a new request
        $timeDiff = microtime(true) - self::$_lastRequestTime;
        if ($timeDiff < 1) {
            usleep((1 - $timeDiff) * 1000000);
        }

        $this->getHttpClient()->resetParameters();

        $this->getHttpClient()->setParameterGet('u', $this->_authUsername);
        $this->getHttpClient()->setParameterGet('k', $this->_authApiKey);
        $this->getHttpClient()->setParameterGet('o', $operation);

        foreach ($getParams as $getKey => $getValue) {
            $this->getHttpClient()->setParameterGet($getKey, $getValue);
        }

        switch ($type) {
            case 'xml':
                $this->getHttpClient()->setParameterGet('f', 'xml');
                $this->getHttpClient()->setUri(self::API_URI);
                break;
            case 'html':
                $this->getHttpClient()->setParameterGet('f', 'html');
                $this->getHttpClient()->setUri(self::API_URI);
                break;
            default:
                /**
                 * @see Zend_Service_Exception
                 */
                require_once 'Zend/Service/Exception.php';
                throw new Zend_Service_Exception('Unknown request type');
        }

        $this->getHttpClient()->setMethod(empty($postParams) ? 'GET' : 'POST');

        if (!empty($postParams)) {
            $this->getHttpClient()->setParameterPost($postParams);
        }

        self::$_lastRequestTime = microtime(true);
        $response = $this->getHttpClient()->request();

        if (!$response->isSuccessful()) {
            /**
             * @see Zend_Service_Exception
             */
            require_once 'Zend/Service/Exception.php';
            throw new Zend_Service_Exception("Http client reported an error: '{$response->getMessage()}'");
        }

        $responseBody = $response->getBody();

        switch ($type) {
            case 'xml':
                $dom = new DOMDocument();

                if (!@$dom->loadXML($responseBody)) {
                    /**
                     * @see Zend_Service_Exception
                     */
                    require_once 'Zend/Service/Exception.php';
                    throw new Zend_Service_Exception('XML Error');
                }

                return $dom;
            case 'html':
                $dom = new DOMDocument();

                if (!@$dom->loadHTML($responseBody)) {
                    /**
                     * @see Zend_Service_Exception
                     */
                    require_once 'Zend/Service/Exception.php';
                    throw new Zend_Service_Exception('HTML Error');
                }

                return $dom;
        }
    }

    /**
     * Transform XML string to array
     *
     * @param   DOMDocument $response
     * @param   string      $root     Name of root tag
     * @param   string      $child    Name of children tags
     * @param   string      $attKey   Attribute of child tag to be used as a key
     * @param   string      $attValue Attribute of child tag to be used as a value
     * @return  array
     * @throws  Zend_Service_Exception
     */
    private static function _xmlResponseToArray(DOMDocument $response, $root, $child, $attKey, $attValue)
    {
        $rootNode = $response->documentElement;
        $arrOut = array();

        if ($rootNode->nodeName == $root) {
            $childNodes = $rootNode->childNodes;

            for ($i = 0; $i < $childNodes->length; $i++) {
                $currentNode = $childNodes->item($i);

                switch ($currentNode->nodeName) {
                    case 'query':
                    case 'querywords':
                    case 'count':
                        $arrOut[$currentNode->nodeName] = $currentNode->nodeValue;
                        break;
                    case 'result':
                        $resultChildNodes = $currentNode->childNodes;

                        $indexTag = $currentNode->getElementsByTagName("index");
                        $index = $indexTag->item(0)->nodeValue;

                        $urlTag = $currentNode->getElementsByTagName("url");
                        $url = $urlTag->item(0)->nodeValue;

                        $titleTag = $currentNode->getElementsByTagName("title");
                        $title = $titleTag->item(0)->nodeValue;

                        $textsnippetTag = $currentNode->getElementsByTagName("textsnippet");
                        $textsnippet = $textsnippetTag->item(0)->nodeValue;

                        $htmlsnippetTag = $currentNode->getElementsByTagName("htmlsnippet");
                        $htmlsnippet = $htmlsnippetTag->item(0)->nodeValue;

                        $minwordsmatchedTag = $currentNode->getElementsByTagName("minwordsmatched");
                        $minwordsmatched = $minwordsmatchedTag->item(0)->nodeValue;

                        $arrOut['result'][$index] = array(
                            'url' => $url,
                            'title' => $title,
                            'textsnippet' => $textsnippet,
                            'htmlsnippet' => $htmlsnippet,
                            'minwordsmatched' => $minwordsmatched,
                        );
                        break;
                    default:
                        break;
                }
            }
        }
        else {
            /**
             * @see Zend_Service_Exception
             */
            require_once 'Zend/Service/Exception.php';
            throw new Zend_Service_Exception('copyscape web service has returned something odd');
        }

        return $arrOut;
    }

    /**
     * Constructs array from XML response
     *
     * @param   DOMDocument $response
     * @return  array
     * @throws  Zend_Service_Exception
     */
    private function _parseXmlResponse(DOMDocument $response)
    {
        $rootNode = $response->documentElement;

        if ($rootNode->nodeName == 'response') {
            return self::_xmlResponseToArray($response, 'response', 'result', 'index', 'minwordsmatched');
        }
        elseif ($rootNode->nodeName == 'remaining') {
            $totalTag = $rootNode->getElementsByTagName("total");
            $todayTag = $rootNode->getElementsByTagName("today");

            $arrOut = array(
                'total' => $totalTag->item(0)->nodeValue,
                'today' => $todayTag->item(0)->nodeValue,
            );
            return $arrOut;
        }
        else {
            /**
             * @see Zend_Service_Exception
             */
            require_once 'Zend/Service/Exception.php';
            throw new Zend_Service_Exception('copyscape web service has returned something odd');
        }
    }
}
