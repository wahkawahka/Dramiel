<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2016 Robert Sardinia
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
use discord\discord;

/**
 * Class auth
 */
class auth
{
    /**
     * @var
     */
    var $config;
    /**
     * @var
     */
    var $discord;
    /**
     * @var
     */
    var $logger;
    var $solarSystems;
    var $triggers = array();
    var $excludeChannel;
    var $nameEnforce;
    public $guildID;
    public $db;
    public $dbUser;
    public $dbPass;
    public $dbName;
    public $ssoUrl;
    public $allianceTickers;
    public $corpTickers;
    public $authGroups;
    public $guild;

    /**
     * @param $config
     * @param $discord
     * @param $logger
     */
    function init($config, $discord, $logger)
    {
        $this->config = $config;
        $this->discord = $discord;
        $this->logger = $logger;
        $this->db = $config['database']['host'];
        $this->dbUser = $config['database']['user'];
        $this->dbPass = $config['database']['pass'];
        $this->dbName = $config['database']['database'];
        $this->corpTickers = $config['plugins']['auth']['corpTickers'];
        $this->nameEnforce = $config['plugins']['auth']['nameEnforce'];
        $this->ssoUrl = $config['plugins']['auth']['url'];
        $this->excludeChannel = $this->config['bot']['restrictedChannels'];
        $this->authGroups = $config['plugins']['auth']['authGroups'];
        $this->guild = $config['bot']['guild'];
    }

    /**
     *
     */
    function tick()
    {
    }

    /**
     * @param $msgData
     * @param $message
     * @return null
     */
    function onMessage($msgData, $message)
    {
        $channelID = (int) $msgData['message']['channelID'];

        if (in_array($channelID, $this->excludeChannel, true)) {
            return null;
        }

        $this->message = $message;
        $userID = $msgData['message']['fromID'];
        $userName = $msgData['message']['from'];
        $message = $msgData['message']['message'];
        $channelInfo = $this->message->channel;
        $guildID = $channelInfo[@guild_id];
        $data = command($message, $this->information()['trigger'], $this->config['bot']['trigger']);
        if (isset($data['trigger'])) {
            if (isset($this->config['bot']['primary'])) {
                if ($guildID != $this->config['bot']['primary']) {
                    $this->message->reply('**Failure:** The auth code your attempting to use is for another discord server');
                    return null;
                }

            }
            // If config is outdated
            if (null === $this->authGroups) {
                $this->message->reply('**Failure:** Please update the bots config to the latest version.');
                return null;
            }

            $code = $data['messageString'];
            $result = selectPending($this->db, $this->dbUser, $this->dbPass, $this->dbName, $code);

            if (strlen($code) < 12) {
                $this->message->reply('Invalid Code, check ' . $this->config['bot']['trigger'] . 'help auth for more info.');
                return null;
            }

            while ($rows = $result->fetch_assoc()) {
                $charID = (int) $rows['characterID'];
                $corpID = (int) $rows['corporationID'];
                $allianceID = (int) $rows['allianceID'];

                //If corp is new store in DB
                $corpInfo = getCorpInfo($corpID);
                if (null === $corpInfo) {
                    $url = "https://api.eveonline.com/corp/CorporationSheet.xml.aspx?corporationID={$corpID}";
                    $xml = makeApiRequest($url);
                    foreach ($xml->result as $corporation) {
                        $corpTicker = $corporation->ticker;
                        $corpName = $corporation->corporationName;
                    }
                    addCorpInfo($corpID, $corpTicker, $corpName);
                } else {
                    $corpTicker = $corpInfo['corpTicker'];
                }

                $url = "https://api.eveonline.com/eve/CharacterName.xml.aspx?ids=$charID";
                $xmlCharacter = makeApiRequest($url);


                // We have an error, show it it
                if ($xmlCharacter->error) {
                    $this->message->reply('**Failure:** Eve API error, please try again in a little while.');
                    return null;
                }

                // Check that the api is working
                if (!isset($xmlCharacter->result->rowset->row)) {
                    $this->message->reply('**Failure:** Eve API error, please try again in a little while.');
                    return null;
                }

                //Add corp ticker to name
                if ($this->corpTickers == 'true') {
                    $setTicker = 1;
                }

                //Set eve name if nameCheck is true
                if ($this->nameEnforce == 'true') {
                    $nameEnforce = 1;
                }

                $allianceRoleSet = 0;
                $corpRoleSet = 0;

                foreach ($xmlCharacter->result->rowset->row as $character) {
                    $roles = $this->message->channel->guild->roles;
                    $member = $this->message->channel->guild->members->get('id', $userID);
                    $eveName = $character->attributes()->name;
                    foreach ($this->authGroups as $authGroup) {
                        //Check if corpID matches
                        if ($corpID === $authGroup['corpID']) {
                            foreach ($roles as $role) {
                                if ($role->name == $authGroup['corpMemberRole']) {
                                    $member->addRole($role);
                                    $corpRoleSet = 1;
                                }
                            }
                        }
                        //Check if allianceID matches
                        if ($allianceID === $authGroup['allianceID'] && $authGroup['allianceID'] != 0) {
                            foreach ($roles as $role) {
                                if ($role->name == $authGroup['allyMemberRole']) {
                                    $member->addRole($role);
                                    $allianceRoleSet = 1;
                                }
                            }
                        }
                        if ($allianceRoleSet === 1 || $corpRoleSet === 1) {
                            $guild = $this->discord->guilds->get('id', $guildID);
                            insertUser($this->db, $this->dbUser, $this->dbPass, $this->dbName, $userID, $charID, $eveName, 'corp');
                            disableReg($this->db, $this->dbUser, $this->dbPass, $this->dbName, $code);
                            $msg = ":white_check_mark: **Success:** {$userName} has been successfully authed.";
                            $this->logger->addInfo("auth: {$eveName} authed");
                            $this->message->reply($msg);
                            //Add ticker if set and change name if nameEnforce is on
                            if (isset($setTicker) || isset($nameEnforce)) {
                                if (isset($setTicker) && isset($nameEnforce)) {
                                    $nick = "[{$corpTicker}] {$eveName}";
                                } elseif (!isset($setTicker) && isset($nameEnforce)) {
                                    $nick = "{$eveName}";
                                } elseif (isset($setTicker) && !isset($nameEnforce)) {
                                    $nick = "[{$corpTicker}] {$userName}";
                                }
                            }
                            if (isset($nick)) {
                                queueRename($userID, $nick, $this->guild);
                            }
                            $guild->members->save($member);
                            return null;
                        }
                    }
                    $this->message->reply('**Failure:** There are no roles available for your corp/alliance.');
                    $this->logger->addInfo('Auth: User was denied due to not being in the correct corp or alliance ' . $eveName);
                    return null;
                }
            }
            $this->message->reply('**Failure:** There was an issue with your code.');
            $this->logger->addInfo('Auth: User was denied due to not being in the correct corp or alliance ' . $userName);
            return null;
        }
        return null;
    }

    /**
     * @return array
     */
    function information()
    {
        return array(
            'name' => 'auth',
            'trigger' => array($this->config['bot']['trigger'] . 'auth'),
            'information' => 'SSO based auth system. ' . $this->ssoUrl . ' Visit the link and login with your main EVE account, select the correct character, and put the !auth <string> you receive in chat.'
        );
    }

    function onMessageAdmin()
    {
    }
}