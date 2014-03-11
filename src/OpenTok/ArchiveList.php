<?php

namespace OpenTok;

use OpenTok\Util\Client;
use OpenTok\Util\Validators;

// TODO: may want to implement the ArrayAccess interface in the future
// TODO: what does implementing JsonSerializable gain for us?
class ArchiveList {

    private $json;
    private $apiKey;
    private $apiSecret;
    private $client;
    private $items;

    public function __construct($archiveListJson, $options = array())
    {
        // unpack optional arguments (merging with default values) into named variables
        $defaults = array(
            'apiKey' => null,
            'apiSecret' => null,
            'apiUrl' => 'https://api.opentok.com',
            'client' => null
        );
        $options = array_merge($defaults, array_intersect_key($options, $defaults));
        list($apiKey, $apiSecret, $apiUrl, $client) = array_values($options);

        // validate params
        Validators::validateArchiveListJson($archiveListJson);
        Validators::validateClient($client);

        $this->json = $archiveListJson;

        $this->client = isset($client) ? $client : new Client();
        if (!$this->client->isConfigured()) {
            Validators::validateApiKey($apiKey);
            Validators::validateApiSecret($apiSecret);
            Validators::validateApiUrl($apiUrl);

            $this->client->configure($apiKey, $apiSecret, $apiUrl);
        }
    }

    public function totalCount()
    {
        return $this->json['count'];
    }

    public function items()
    {
        if (!$this->items) {
            $items = array();
            foreach($this->json['items'] as $archiveJson) {
                $items[] = new Archive($archiveJson, array( 'client' => $this->client ));
            }
            $this->items = $items;
        }
        return $this->items;
    }
}

/* vim: set ts=4 sw=4 tw=100 sts=4 et :*/
