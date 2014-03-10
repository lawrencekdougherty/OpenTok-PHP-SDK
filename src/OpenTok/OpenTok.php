<?php

/**
* OpenTok PHP Library
* http://www.tokbox.com/
*
* Copyright (c) 2011, TokBox, Inc.
* Permission is hereby granted, free of charge, to any person obtaining
* a copy of this software and associated documentation files (the "Software"),
* to deal in the Software without restriction, including without limitation
* the rights to use, copy, modify, merge, publish, distribute, sublicense,
* and/or sell copies of the Software, and to permit persons to whom the
* Software is furnished to do so, subject to the following conditions:
*
* The above copyright notice and this permission notice shall be included
* in all copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
* OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
* THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
* LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
* OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
* THE SOFTWARE.
*/

namespace OpenTok;

use OpenTok\Session;
use OpenTok\Archive;
use OpenTok\Role;
use OpenTok\Exception\InvalidArgumentException;
use OpenTok\Exception\UnexpectedResponseException;
use OpenTok\Util\Client;

class OpenTok {

    private $apiKey;
    private $apiSecret;
    private $client;

    public function __construct($apiKey, $apiSecret, $options = array())
    {
        // unpack optional arguments (merging with default values) into named variables
        $defaults = array('apiUrl' => 'https://api.opentok.com', 'client' => null);
        $options = array_merge($defaults, array_intersect_key($options, $defaults));
        list($apiUrl, $client) = array_values($options);

        // validate arguments
        if (!(is_string($apiKey) || is_int($apiKey))) {
            throw new InvalidArgumentException(
                'The apiKey was not a string nor an integer: '.$apiKey,
                600
            );
        }
        if (!(is_string($apiSecret))) {
            throw new InvalidArgumentException('The apiSecret was not a string: '.$apiSecret, 601);
        }
        if (!(is_string($apiUrl))) {
            throw new InvalidArgumentException(
                'The optional apiUrl was not a string: '.$apiUrl,
                602
            );
        }
        if (isset($client) && !($client instanceof Client)) {
            throw new InvalidArgumentException(
                'The optional client was not an instance of \OpenTok\Util\Client',
                603
            );
        }

        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;

        $this->client = isset($client) ? $client : new Client();
        $this->client->configure($apiKey, $apiSecret, $apiUrl);
    }

    /** - Generate a token
     *
     * $session_id  - If session_id is not blank, this token can only join the call with the specified session_id.
     * $role        - One of the constants defined in RoleConstants. Default is publisher, look in the documentation to learn more about roles.
     * $expire_time - Optional timestamp to change when the token expires. See documentation on token for details.
     * $connection_data - Optional string data to pass into the stream. See documentation on token for details.
     */
    public function generateToken($session_id, $options = array())
    {
        // unpack optional arguments (merging with default values) into named variables
        $defaults = array(
            'role' => Role::PUBLISHER,
            'expireTime' => null,
            'data' => null
        );
        $options = array_merge($defaults, array_intersect_key($options, $defaults));
        list($role, $expireTime, $data) = array_values($options);

        // additional token data
        $createTime = time();
        $nonce = microtime(true) . mt_rand();

        // validate arguments
        if(!is_string($session_id) || empty($session_id)){
            throw new InvalidArgumentException(
                'Null or empty session ID are not valid: '.$session_id,
                604
            );
        }
        if (!Role::isValidValue($role)) {
            throw new InvalidArgumentException('Unknown role: '.$role, 607);
        }
        if(!is_null($expireTime)) {
            if(!is_numeric($expireTime)) {
                throw new InvalidArgumentException(
                    'Expire time must be a number: '.$expireTime,
                    608
                );
            }
            if($expireTime < $createTime) {
                throw new InvalidArgumentException(
                    'Expire time must be in the future: '.$expire_time.'<'.$create_time,
                    609
                );
            }
            $in30Days = $createTime + 2592000;
            if($expireTime > $in30Days) {
                throw new InvalidArgumentException(
                    'Expire time must be in the next 30 days: '.$expire_time.'>'.$in30Days,
                    610
                );
            }
        }
        if(!empty($data)) {
            $dataLength = strlen($data);
            if($dataLength > 1000) {
                throw new InvalidArgumentException(
                    'Connection data must be less than 1000 characters. Length: '.$dataLength,
                    611
                );
            }
        }

        // validate session id
        // TODO: write tests for this decoding, fix errors;
        $sub_session_id = substr($session_id, 2);
        $decoded_session_id="";
        for($i=0;$i<3;$i++){
            $new_session_id = $sub_session_id.str_repeat("=",$i);
            $new_session_id = str_replace("-", "+",$new_session_id);
            $new_session_id = str_replace("_", "/",$new_session_id);
            $decoded_session_id = base64_decode($new_session_id);
            if($decoded_session_id){
                break;
            }
        }
        if (strpos($decoded_session_id, "~")===false){
            throw new InvalidArgumentException(
                'An invalid session ID was passed: '.$session_id,
                605
            );
        }else{
            $arr=explode("~",$decoded_session_id);
            if($arr[1]!=$this->apiKey){
                throw new InvalidArgumentException(
                    'The apiKey for the given sessionId does not match this instance: { '.
                        'sessionId: "'.$session_id.'", '.
                        'apiKey: "'.$this->apiKey.'" '.
                    '}',
                    606
                );
            }
        }

        $dataString = "session_id=$session_id&create_time=$createTime&role=$role&nonce=$nonce" .
            (($expireTime) ? "&expire_time=$expireTime" : '') .
            (($data) ? "&connection_data=" . urlencode($data) : '');
        $sig = $this->_sign_string($dataString, $this->apiSecret);

        return "T1==" . base64_encode("partner_id=$this->apiKey&sig=$sig:$dataString");
    }

    /**
     * Creates a new session.
     * $location - IP address to geolocate the call around.
     * $properties - Optional array
     */
    public function createSession($options=array())
    {
        // unpack optional arguments (merging with default values) into named variables
        $defaults = array('p2p' => null, 'location' => null);
        $options = array_merge($defaults, array_intersect_key($options, $defaults));
        list($p2p, $location) = array_values($options);

        $sessionXml = $this->client->createSession($options);
        $sessionId = $sessionXml->Session->session_id;
        if (!$sessionId) {
            throw new UnexpectedResponseException(
                'Failed to create session: XML response did not contain a session_id',
                502,
                $createSessionXML
            );
        }

        return new Session($this, (string)$sessionId, array(
            'location' => $location, 
            'p2p' => $p2p
        ));
    }

    /**
     * Starts archiving an OpenTok 2.0 session.
     * <p>
     * Clients must be actively connected to the OpenTok session for you to successfully start recording an archive.
     * <p>
     * You can only record one archive at a time for a given session. You can only record archives of OpenTok
     * server-enabled sessions; you cannot archive peer-to-peer sessions.
     *
     * @param String $session_id The session ID of the OpenTok session to archive.
     * @param String $name The name of the archive. You can use this name to identify the archive. It is a property
     * of the Archive object, and it is a property of archive-related events in the OpenTok JavaScript SDK.
     * @return OpenTokArchive The OpenTokArchive object, which includes properties defining the archive, including the archive ID.
     */
    public function startArchive($sessionId, $name=null)
    {
        // validate arguments
        if (empty($sessionId) || !is_string($sessionId)) {
            throw new InvalidArgumentException(
                'The sessionId was empty or not a string: '.$sessionId,
                610
            );
        }

        // TODO: this method doesn't check if the sessionId belongs to the apiKey, should it?
        //       if so, then the tests need to be updated.
        //       one justification is that the generateToken method has no opportunity to return
        //       an error for using an incorrect sessionId since there is no server request, but
        //       this method will bubble an error when the server response is recieved if the
        //       sessionId doesn't belong.

        if ($name != null && !is_string($name) /* TODO: length? */) {
            throw new InvalidArgumentException(
                'The name was not a string: '.$name,
                611
            );
        }

        $params = array( 'sessionId' => $sessionId );
        if ($name) { $params['name'] = $name; }

        $archiveJson = $this->client->startArchive($params);
        return new Archive($archiveJson, array( 'client' => $this->client ));
    }

    /**
     * Stops an OpenTok archive that is being recorded.
     * <p>
     * Archives automatically stop recording after 90 minutes or when all clients have disconnected from the
     * session being archived.
     *
     * @param String $archive_id The archive ID of the archive you want to stop recording.
     * @return The OpenTokArchive object corresponding to the archive being stopped.
     */
    public function stopArchive($archiveId)
    {
        // TODO: validate archiveId

        $archiveJson = $this->client->stopArchive($archiveId);
        return new Archive($archiveJson, array( 'client' => $this->client ));
    }

    /**
     * Gets an OpenTokArchive object for the given archive ID.
     *
     * @param String $archive_id The archive ID.
     *
     * @throws OpenTokArchiveException There is no archive with the specified ID.
     * @throws OpenTokArgumentException The archive ID provided is null or an empty string.
     *
     * @return OpenTokArchive The OpenTokArchive object.
     */
    public function getArchive($archiveId)
    {
        // TODO: validate archiveId

        $archiveJson = $this->client->getArchive($archiveId);
        return new Archive($archiveJson, array( 'client' => $this->client ));
    }

    /**
     * Deletes an OpenTok archive.
     * <p>
     * You can only delete an archive which has a status of "available", "uploaded", or "deleted".
     * Deleting an archive removes its record from the list of archives. For an "available" archive,
     * it also removes the archive file, making it unavailable for download. For a "deleted"
     * archive, the archive remains deleted.
     *
     * @param String $archive_id The archive ID of the archive you want to delete.
     *
     * @return Boolean Returns true on success.
     *
     * @throws OpenTokArchiveException There archive status is not "available", "updated",
     * or "deleted".
     */
    public function deleteArchive($archiveId)
    {
        // TODO: validate archiveId

        $archiveJson = $this->client->deleteArchive($archiveId);
        return new Archive($archiveJson, array( 'client' => $this->client, 'deleted' => true ));
    }

    /**
     * Returns an OpenTokArchiveList. The <code>items()</code> method of this object returns a list of
     * archives that are completed and in-progress, for your API key.
     *
     * @param integer $offset Optional. The index offset of the first archive. 0 is offset of the most recently
     * started archive. 1 is the offset of the archive that started prior to the most recent archive. If you do not
     * specify an offset, 0 is used.
     * @param integer $count Optional. The number of archives to be returned. The maximum number of archives returned
     * is 1000.
     * @return OpenTokArchiveList An OpenTokArchiveList object. Call the items() method of the OpenTokArchiveList object
     * to return an array of Archive objects.
     */
    public function listArchives($offset=0, $count=null)
    {
        // validate params
        if (!is_numeric($offset) || ($count != null && !is_numeric($count))) {
            throw new InvalidArgumentException(
                'The offset or count were not valid numbers: offset='.$offset.' count='.$count,
                612
            );
        }

        $archiveListJson = $this->client->listArchives($offset, $count);
        return new ArchiveList($archiveListJson, array( 'client' => $this->client ));
    }

    /** @internal */
    private function _sign_string($string, $secret)
    {
        return hash_hmac("sha1", $string, $secret);
    }
}

/* vim: set ts=4 sw=4 tw=100 sts=4 et :*/
