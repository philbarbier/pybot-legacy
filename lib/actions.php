<?php
class Actions
{
    public function __construct($config)
    {
        $this->config = $config;
        $this->connection = new Mongo($this->config['mongodb']);
        $this->collection = $this->connection->pybot;
        $this->socket = null;
        $this->version = $config['versionString'];
        $this->txlimit = 256; // transmission length limit in bytes (chars)
        $this->currentuser = '';
        $this->currentchannel = false;
        $this->christmasDate = null;
        $this->_reloadCount = 0;
        if (!isset($this->config['channellist'])) $this->config['channellist'] = array();
        
        $this->_setLastPrivmsg();
        
        $this->message_data = '';
        $this->isIRCOper = false;
        // seperate config array incase we need to override anything(?)
        $linguo_config = $this->config;
        if (!isset($linguo_config['_origclass'])) {
            $linguo_config['_origclass'] = __CLASS__;
        }
        if (isset($linguo_config['_callee'])) {
            $linguo_config['_callee'][] = $linguo_config['_origclass'];
        }
        if (array_key_exists(__CLASS__, $this->config['_classes'])) {
            $ircClass = $this->config['_ircClassName'];
            $ircClass::setCallList(__CLASS__, $this->config['_callee']);
        }

        $class = $this->config['_classes']['Linguo']['classname'];
        $this->linguo = new $class($linguo_config);
        $class = $this->config['_classes']['Twitter']['classname']; 
        $this->twitter = new $class($linguo_config);
        $class = $this->config['_classes']['Log']['classname']; 
        $this->Log = new $class($linguo_config);
        $class = $this->config['_classes']['Curl']['classname']; 
        $this->curl = new $class($linguo_config);

        if (!isset($this->config['usercache'])) $this->config['usercache'] = array();
        $this->array_key = '';
        if (!isset($this->config['bothandle'])) $this->config['bothandle'] = false;
        $this->myparts = array();
        $this->bartUseCount = 1;
        $this->public_commands = array();
        $this->_cacheData = array();
        $this->_setKickWordCache();
        if (!$this->connection) {
            sleep(10);
            // try again
            $this->connection = new Mongo($this->config['mongodb']);
        }
    }

    public function __destruct()
    {
        // unload
    }

    public function _setConfig($key = false, $data = false)
    {
        if (!$key || !$data) return;
        $this->config[$key] = $data;

    }

    public function _getConfig()
    {
        return $this->config;
    }

    public function initModule($className = false, $config = false)
    {
        if (!$className || !$config) return;
        $selfRef = strtolower($config['_origclass']);
        $this->$selfRef = new $className($config);
    }

    private function _check_permissions($nick = '')
    {
        return in_array($nick, $this->config['banned_users']);
    }

    /* Sets socket for writing to IRC */
    public function setsocket($socket)
    {
        $this->socket = $socket;
    }

    /* calculates a date timespan (mimicing CI function) */
    private function _calculate_timespan($seconds = 1, $time = '', $display_mins_secs = true)
    {
        if (!is_numeric($seconds)) {
            $seconds = 1;
        }

        if (!is_numeric($time)) {
            $time = time();
        }

        if ($time <= $seconds) {
            $seconds = 1;
        } else {
            $seconds = $time - $seconds;
        }

        $str = '';
        $years = floor($seconds / 31536000);

        if ($years > 0) {
            $str .= $years.' '.(($years > 1) ? 'years' : 'year').', ';
        }

        $seconds -= $years * 31536000;
        $months = floor($seconds / 2628000);

        if ($years > 0 or $months > 0) {
            if ($months > 0) {
                $str .= $months.' '.(($months   > 1) ? 'months' : 'month').', ';
            }
            $seconds -= $months * 2628000;
        }

        $weeks = floor($seconds / 604800);

        if ($years > 0 or $months > 0 or $weeks > 0) {
            if ($weeks > 0) {
                $str .= $weeks.' '.(($weeks > 1) ? 'weeks' : 'week').', ';
            }

            $seconds -= $weeks * 604800;
        }

        $days = floor($seconds / 86400);

        if ($months > 0 or $weeks > 0 or $days > 0) {
            if ($days > 0) {
                $str .= $days.' '.(($days   > 1) ? 'days' : 'day').', ';
            }
            $seconds -= $days * 86400;
        }

        $hours = floor($seconds / 3600);

        if ($days > 0 or $hours > 0) {
            if ($hours > 0) {
                $str .= $hours.' '.(($hours > 1) ? 'hours' : 'hour').', ';
            }
            $seconds -= $hours * 3600;
        }

        // don't display minutes/seconds unless $display_mins_secs
        // == true
        if ($display_mins_secs) {
            $minutes = floor($seconds / 60);
            if ($days > 0 or $hours > 0 or $minutes > 0) {
                if ($minutes > 0) {
                    $str .= $minutes.' '.(($minutes > 1) ? 'minutes' : 'minute').', ';
                }
                $seconds -= $minutes * 60;
            }

            if ($str == '') {
                $str .= $seconds.' '.(($seconds > 1) ? 'seconds' : 'second').', ';
            }
        }

        return substr(trim($str), 0, -1);
    }

    /* Writes messages to IRC socket */
    private function write($type, $channel = null, $message = null)
    {
        if (!isset($type) || is_numeric($type)) return;

        if ($type == 'QUIT') {
            $quitmsg = $type.' :'.$message; //."\r\n\r\n";

            return Irc::_write($quitmsg); // $this->socket, $quitmsg, strlen($quitmsg));
        }

        if (substr($type, 0, 4) == 'NICK' && !is_null($channel)) {
            $nickchange = $type; // . "\r\n\r\n";
            Irc::_write($nickchange);
            $this->_setBotHandle($channel);
            return;
        }

        if (!$channel) {
            $channel = $this->config['default_chan'];
        }
        if (strlen($message) > $this->txlimit) {
            // @TODO gots to make this substr nicely (look for the last space)
            $message_parts = $this->_split_message($message);
            $count = count($message_parts);
            foreach ($message_parts as $message_part) {
                // just in case!
                $message_part = preg_replace('/\\r\\n/', ' ', $message_part);
                $msg = "$type $channel :$message_part"; //\r\n\r\n";
                Irc::_write($msg); //fwrite($this->socket, $msg, strlen($msg));
                if ($count > 20) {
                    sleep(1);
                }
            }

            return true;
        } elseif (!$message) {
            $msg = "$type $channel"; //\r\n\r\n";
            if (!$channel) $msg = "$type"; //\r\n\r\n";
        } else {
            $message = preg_replace('/\\r\\n/', ' ', $message);
            $msg = "$type $channel :$message"; //\r\n\r\n";
        }

        return Irc::_write($msg); //fwrite($this->socket, $msg, strlen($msg));
    }

    // uses class variable "txlimit" to split the message string up
    // in order to ensure the IRC server receives the full message
    // and doesn't flood the server

    private function _split_message($full_message = '')
    {
        if (empty($full_message)) {
            return false;
        }
        
        $parts = array();
        $j = 0;

        $message_parts = preg_split('/\\r\\n/', $full_message);
        foreach($message_parts as $part) {
            if (strlen($part) > $this->txlimit) {
                $prevpos = false;
                for ($i = 0; $i < strlen($part); $i = $i + $this->txlimit) {
                    $start = (!$prevpos) ? $i : ($start + $prevpos);
                    $prevpos = $this->txlimit - strpos(strrev(substr($part, $start, $this->txlimit)), ' ');
                    $parts[$j] = substr($part, $start, $prevpos);
                    $j++;
                }
            } else {
                $parts[$j] = $part;
                $j++;
            }
        }

        return $parts;
    }

    public function set_parts($parts = array())
    {
        if (count($parts) == 0) {
            return false;
        }
        $this->myparts = $parts;
    }

    public function get_parts()
    {
        return $this->myparts;
    }

    /* macro function for writing to the current channel */
    public function write_channel($message, $channel = false)
    {
        if (!$channel) $channel = $this->get_current_channel();
        if (is_array($message)) {
            foreach($message as $k => $v) {
                if (is_array($v)) {
                    $this->write('PRIVMSG', $channel, $k);
                    foreach($v as $key => $val) {
                        $this->write('PRIVMSG', $channel, $key . ' => ' . $val);
                    }
                } else {
                    $this->write('PRIVMSG', $channel, $k . ' => ' . $v);
                }
            }
            return;
        }
        $this->write('PRIVMSG', $channel, $message);
    }

    /* macro function for writing to the current user */
    private function write_user($message)
    {
        $this->write('PRIVMSG', $this->get_current_user(), $message);
    }

    /* Called for every action (join/part/privmsg etc) 
       Use this for any event that should fire every time something happens. */
    public function catchall($data)
    {
        if (!$data || is_null($data)) {
            return;
        }

        // make sure we have text content, that it's a message of some sort and make sure we're not logging ourself
        if (!empty($data['message']) && ($data['command'] == 'PRIVMSG') && ($data['user'] != $this->config['bothandle'])) {

            if (isset($this->config['log_history']) && $this->config['log_history']) {
                try {
                    $this->collection->log->insert($data);
                } catch (Exception $e) {
                    $this->Log->log('DB Error', 2);
                }
            }
            // log youtube stuffs
            if (stristr($data['message'], 'youtube.com') || stristr($data['message'], 'youtu.be')) {
                preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $data['message'], $match);
                $url = @$match[0][0];
                if (strpos($data['message'], 'ythist', 0) === false) {
                    $this->_logYoutube($url);
                }
                $data['urltitle'] = $this->get_site_title($url);
            }

            // check for a $word in the text
            $this->_checkForWord($data);

            
            # There are some things we just don't tolerate...
		    $this->_kickwords($data);
        }
        
        // handle uptime records
        $this->_ooglyBooglyUptime($data);

        if ($this->config['log_stats']) {
            $this->stats($data);
        }
        if (isset($data['command']) && $data['command'] == 'JOIN' && $data['user'] != $this->config['bothandle']) {
            // abuse new user
            sleep(2);
            $this->abuse(array('arg1' => $data['user'], 'joinabuse' => true));
            // autovoice
            if (isset($data['channel']) && $this->_getChannelData($data['channel'], 'autovoice')) {
                $this->_changeMode($data['user'], $data['channel'], '+v');
            }
        }

		
        if (isset($data['command']) && (($data['command'] == 'MODE') || ($data['command'] == 'JOIN') || ($data['command'] == 'PRIVMSG'))) {
            /*
            this just tracks channel data:
                - Last channel event (for the quiet spice)
                - sets channel modes up
            */

            if (isset($data['channel'])) {
                $this->_setLastPrivmsg($data['channel']);
                if (($this->_getChannelData($data['channel']))) {
                    // set the channel modes either from defaults, or if they're specified in the config file
                    if (!($this->_getChannelData($data['channel'], 'modes'))) {
                        $this->_changeMode(false, $data['channel'], $this->config['default_modes']);
                        $this->_setChannelData($data['channel'], 'modes', $this->config['default_modes']);
                    } else {
                        if (!$this->_hasSameLetters($this->_getChannelData($data['channel'], 'channelmodes'), $this->_getChannelData($data['channel'], 'modes'))) {
                            $this->_changeMode(false, $data['channel'], '-' . str_replace('+', '', $this->_getChannelData($data['channel'], 'channelmodes')) . '+' . $this->_getChannelData($data['channel'], 'modes'));
                        }
                    }
                }
            }

            // <3 :D

            $minute = rand(0,59);
            $time = strtotime('12:' . $minute);
            $now = date('U');
            if (!is_null($this->christmasDate)) {
                if ($now <= ($this->christmasDate - (86220 - ($minute / 60)))) {
                    $this->christmasDate = null;
                }
            }
            if ($now >= ($time - 180) && ($now <= ($time + 180))) $this->_christmas($data);

            // this is to check that the bot is using the configured nickname

            // this is just in case the bot has been trying to change nick too many times
            if (isset($this->config['_nickChangeString']) && (time() >= $this->config['_nickChangeTime'])) {
                $this->write('NICK ' . $this->config['_nickChangeString'], $this->config['_nickChangeString']);
                unset($this->config['_nickChangeString']);
            }

            // this is the base check to ensure that the bots nick matches the config
            if ($this->config['bothandle'] != $this->config['irc_handle']) {
                $this->write('NICK ' . $this->config['irc_handle'], $this->config['irc_handle']); 
            }
        }

        // only run check_url if we actually see a URL
        // *** can be expanded to look for 'www.' as well
        if (isset($data['message']) && preg_match('/http[s]?:\/\//', $data['message']) > 0) {
            if (!is_numeric(strpos($data['message'], 'ythist', 0))) {
                $this->check_url(explode(' ', $data['message']), $this->get_current_channel());
            }
        }
        
        $time = time();
        $bit = rand(5400, 7200);
        $topicbit = rand(86400,86401);

        if (isset($data['command'])) {
            foreach($this->config['channellist'] as $channel => $v) {
                // check channel topics, switch it up if they're old AF
                if (($this->_getChannelData($channel, 'topicchange')) && $this->_getChannelData($channel, 'topicchange')) {
                    if (($this->_getChannelData($channel, 'topicdate'))) {
                        if (($time - $this->_getChannelData($channel, 'topicdate')) != $time && (($time - $this->_getChannelData($channel, 'topicdate')) > $topicbit)) {
                            $this->_setTopic($channel);
                        }
                    } else {
                        //set a topic!
                        $this->_setTopic($channel);
                    }
                }

                // if it's been quiet for a bit, say something!
                if (($this->_getChannelData($channel, 'lastPrivmsg'))) {
                    if (($time - $this->_getChannelData($channel, 'lastPrivmsg')) != $time && (($time - $this->_getChannelData($channel, 'lastPrivmsg')) > $bit)) {
                        if ($this->_getChannelData($channel, 'keepquiet') === false) {
                            $this->linguo->setLastRequester($this->config['bothandle']);
                            $deadair = $this->_getDeadAir($channel);
                            $this->write_channel($deadair, $channel);
                            $this->_setChannelData($channel, 'lastPrivmsg', time());
                        }
                    }
                }
            }
        }
    }
    
    // keep track of the uptime record
    private function _ooglyBooglyUptime($args = array())
    {
        $now = date('U');
        $diff = $now - $this->config['_starttime'];

        if ($uptime = file_get_contents('./uptime')) {
            //$this->config['_starttime'] 
            if (is_numeric($uptime) && ($uptime < $diff)) {
                $this->_writeUptime($diff);
            }
            $this->_calculate_timespan($this->config['_starttime']);
        } else {
            $this->_writeUptime($diff);
        }
    }

    private function _writeUptime($uptime = 0)
    {
        if (is_numeric($uptime) || $uptime == 0) {
            try {
                file_put_contents('./uptime', $uptime);
            } catch (Exception $e) {
                $this->_debug('uptime write failure: ' . $uptime);
            }
        }
    }

    public function topic($args = array())
    {
        $channel = $this->get_current_channel();
        // make sure it's not being done too frequently
        $time = time();
        if (($this->_getChannelData($channel, 'topicdate'))) {
            if (($time - $this->_getChannelData($channel, 'topicdate')) > 600) {
                $text = false;
                if (!empty($args['arg1'])) $text = $args['arg1'];
                $this->_setTopic($channel, $text, $args);
            } else {
                $this->write_channel('Too soon, pantaloon!');
            }
        } else {
            $text = false;
            if (!empty($args['arg1'])) $text = $args['arg1'];
            $this->_setTopic($channel, $text, $args);
        }
    }

    public function _hasSameLetters($string1 = false, $string2 = false)
    {
        if (!$string1 || !$string2) return false;

        if (strlen($string1) !== strlen($string2)) return false;

        $string1 = str_replace('+', '', $string1);
        $string2 = str_replace('+', '', $string2);
        
        $data = '';

        for ($i = 0; $i < strlen($string1); $i++) {
            if (strstr($string2, $string1[$i])) {
                $data .= $string1[$i];
            }
        }
        return (strlen($string1) == strlen($data));
    }

    private function _changeMode($user = false, $channel = false, $mode = false)
    {
        if (!$channel || !$mode) return;

        if ($user) {
            $str = 'MODE ' . $channel . ' ' . $mode . ' ' . $user;
        } else {
            $str = 'MODE ' . $channel . ' ' . $mode;
        }
        $this->write($str);
        return;
    }

    public function _setChannelData($channel = false, $key = false, $value = false)
    {
        if (!$channel || !$key || !$value) return;
        $this->config['irc_channels'][$channel][$key] = $value;
        return;
    }

    public function _getChannelData($channel = false, $key = false)
    {
        if (!$channel || !isset($this->config['irc_channels'][$channel])) return false;
        if (!$key && isset($this->config['irc_channels'][$channel])) {
            return $this->config['irc_channels'][$channel];
        }
        return isset($this->config['irc_channels'][$channel][$key]) ? $this->config['irc_channels'][$channel][$key] : false;
    }

    private function _setTopic($channel = false, $text = false, $args = array())
    {
        if (!$channel) return;
        if (!$text) {
            $this->linguo->setLastRequester($this->config['bothandle']);
            $text = $this->linguo->get_rant($args, true);
        }
        if (strlen($text) >= ($this->txlimit - 56)) {
            return $this->_setTopic($channel, false, $args);
        }
        return $this->write('TOPIC', $channel, $text);
    }

    // spiceh!

    private function _getDeadAir($chan = false)
    {
        if (!$chan) return;
        $undead = array();
        $undead[$chan] = array();
        $word = $this->linguo->get_word('exclamation');
        $word2 = $this->linguo->get_word('adjective');
        $undead[$chan][] = $word['word'] . '! It sure is quiet in here... ' . $word2['word'] . 'ly quiet.'; 
        $word = $this->linguo->get_word('exclamation');
        $undead[$chan][] = $this->linguo->testtpl(array('arg1' => 'It\'s quieter in here than when ' . $this->randuser() . ' got arrested for $crime in his $relatives house. You know, that place near $place. Good thing they didn\'t find the bags of $drug in his $hole!'));
        $undead[$chan][] = $word['word'] . "! I tell ya it's quiet... Why... " . $this->linguo->get_rant(array(), true);
        $user = $this->randuser();
        if ($user == $this->config['bothandle']) $user = $this->randuser();
        $undead[$chan][] = $this->linguo->testtpl(array('arg1' => '$exclamation! Does it smell like $odour in here or something? ' . $user . ' you better start chatting or I\'ll $threat!'));
        $user = $this->randuser();
        if ($user == $this->config['bothandle']) $user = $this->randuser();
        $undead[$chan][] = $this->linguo->testtpl(array('arg1' => 'Somebody better get talking in here or I\'ll $threat! I\'m looking at you, ' . $user));

        $this->_setUndead($undead);
        $rand = rand(0, (count($this->config['undead'][$chan]) - 1));
        $text = $this->config['undead'][$chan][$rand];
        return $text;
    }

    private function _setUndead($undead = array())
    {
        // initial build
        if (!isset($this->config['undead'])) {
            $dead = array('Big gulps, huh?', 'MMMMMBOP!');
            $this->config['undead'] = array();
            foreach($this->config['channellist'] as $channel => $v) {
                $this->config['undead'][$channel] = $dead;
            }
        }
      
        foreach($undead as $channel => $zombie) {
            foreach ($zombie as $k => $zooma) {
                $this->config['undead'][$channel][] = $zooma;
            }
        }
    }

    private function _checkForWord($data = false)
    {
        if (!$data) return;
       
        $pr = preg_match('/\$[a-zA-Z]/', $data['message']);
        if ($pr == 0) return;
        // check first word only, send it back if it's a command

        // thoughts for the future, make this pick up the $technique:
        // Flimflam and aster1sk : doing the $technique since 2010

        foreach(explode(' ', $data['message']) as $word) {
            if (method_exists($this, $word)) return;
            break;
        }

        $this->write_channel($this->linguo->testtpl(array('arg1' => $data['message'])));

    }

    private function _logYoutube($url = false, $user = false, $time = false)
    {
        if (!$url) return;

        $videoId = $this->_getVideoId($url);
        
        if (!$videoId) return;

        // see if we have this video in the log already
        $criteria = array(
            'videoid' => array(
                '$regex' => new MongoRegex('/' . $videoId . '/'),
            ),
        );

        $c = $this->collection->irc->youtubestats;

        $yt = $c->findOne($criteria);
       
        if (!isset($yt['title'])) {
            $title = $this->get_site_title($url);
        } else {
            $title = $yt['title'];
        }
        
        $title = str_ireplace(' - YouTube', '', $title);

        $data = array('videoid' => $videoId, 'title' => $title);
        $data['user'] = $this->get_current_user();

        if (!isset($yt['firstuser'])) {
            $data['firstuser'] = $this->get_current_user();
            if ($user) {
                $data['firstuser'] = $user;
            }
        } else {
            $data['firstuser'] = $yt['firstuser'];
        }

        if ($user) {
            $data['user'] = $user;
        }

        $watchcount = 1;
        if (isset($yt['watchcount'])) {
            $watchcount = (int)$yt['watchcount'] + 1;
        }

        $data['watchcount'] = $watchcount;

        $data['firstwatch'] = time();
        
        if (isset($yt['firstwatch'])) {
            $data['firstwatch'] = $yt['firstwatch'];
        }

        $data['time'] = time();
        if (is_numeric($time)) {
            if (!isset($yt['firstwatch'])) {
                $data['firstwatch'] = $time;
            }
            $data['time'] = $time;
        }

        $c->update($criteria, $data, array('upsert' => true));

    }

    private function _getVideoId($url = false)
    {
        if (!$url) return false;
        // get the video ID
        $d = explode('v=', $url);
        if (!isset($d[1])) {
            // might be a youtu.be/<id> URL, check that too
            if (stristr($url, 'youtu.be')) {
                $d = explode('.be/', $url);
            } else {
                return false;
            }
        }
        $videoId = $d[1];
        $spos = strpos($d[1], '&');
        
        if ($spos) {
            $videoId = substr($d[1], 0, $spos);
        }
        return preg_replace('/[^a-zA-Z0-9\-\_]/', '', $videoId);
    }

    public function ythist($args = array())
    {
        if (!isset($args['arg1'])) return;
        $url = false;
        if (stristr($args['arg1'], 'youtube.com') || stristr($args['arg1'], 'youtu.be')) {
            preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $args['arg1'], $match);
            $url = @$match[0][0];
        }

        if (!$url) return;

        $videoId = $this->_getVideoId($url); 
        // see if we have this video in the log already

        $criteria = array(
            'videoid' => array(
                '$regex' => new MongoRegex('/' . $videoId . '/'),
            ),
        );

        $c = $this->collection->irc->youtubestats->findOne($criteria);

        if (!isset($c['videoid'])) {
            $this->write_channel('Not found in history'); 
            return;
        }

        $str = 'This video, "' . $c['title'] . '" was first linked by ' . $c['firstuser'] . ' on ';
        $str .= date($this->config['_dateFormat'] , $c['firstwatch']) . '. It has been linked ' . $c['watchcount'] . ' time';
        if ((int)$c['watchcount'] > 1) $str .= 's';
        $str .= '. It was last linked on ' . date($this->config['_dateFormat'], $c['time']) . ' by ' . $c['user'];

        $this->write_channel($str);

    }

    public function ytstats($args = array())
    {
        $offset = (isset($args['offset']) && is_numeric($args['offset'])) ? $args['offset'] : false;

        $username = isset($args['username']) ? trim($args['username']) : false;

        $criteria = array();

        if ($username) {
			$criteria['user'] = $username;
		}

        $results = $this->collection->irc->youtubestats->find($criteria); //->limit(5);
        $results->sort(array('watchcount' => -1));
        $count = $results->count();


        if ($offset && (($offset > 0) && ($offset < $count))) {
            $results = $results->skip($offset);
        }

        $results = $results->limit(5);        

        foreach ($results as $row => $data) {
            $str = '"' . $data['title'] . '" was first linked by ' . $data['firstuser'] . ' on ';
            $str .= date($this->config['_dateFormat'] , $data['firstwatch']) . '. It has been linked ' . $data['watchcount'] . ' time';
            if ((int)$data['watchcount'] > 1) $str .= 's';
            $str .= '. It was last linked on ' . date($this->config['_dateFormat'], $data['time']) . ' by ' . $data['user'];

            $str .= ' - ' .  $this->_shorten('https://youtube.com/watch?v=' . $data['videoid']); 
            $this->write_channel($str);
        }

        $this->write_channel('There are ' . $count . ' total videos');

    }

    private function _maintenance($args = array())
    {
        return;
        $data = $this->collection->words->find();

        $i = 0;
        foreach ($data as $row) {
            if (!isset($row['word']) || !isset($row['type'])) continue;
            $criteria = array('word' => $row['word'], 'type' => $row['type']);
            $result = iterator_to_array($this->collection->words->find($criteria));
            $early = 0;
            $id = array();
            $j = 0;
            foreach ($result as $rr) {
                if ($j === 0) $first = $rr['_id'];
                if (isset($rr['time'])) {
                    if ($rr['time'] === 0) {
                        $early = $rr['time'];
                        $id[0] = $rr['_id'];
                    }

                    if ($rr['time'] < $early) {
                        $early = $rr['time']; 
                        $id[0] = $rr['_id'];
                    }
                } else {
                    if ($early === 0) {
                        $early = time();
                        // make it the earliest
                        $id[0] = $result[(string)$first]['_id'];
                    }
                }
                $id[] = $rr['_id'];
                $j++;
            }
            if ($j > 1) {

                // delete the newer dupe entries
                //$this->_debug('$id[0] - ' . $id[0]);

                $delcriteria = array(
                                        '_id' => array('$nin' => array(new MongoId((string)$id[0]))),
                                        'word' => $row['word'],
                                        'type' => $row['type']
                                    );
                $delresults = $this->collection->words->find($delcriteria);
                //$this->_debug('removing');
                //$this->_debug(iterator_to_array($delresults));
                //$delresults = $this->collection->words->remove($delcriteria);
                //$this->_debug('remove result');
                //$this->_debug($delresults);
            }
            //if ($j > 1) return;
            if (($i % 500) == 0) {
                $this->_debug('At record ' . $i);
            }
            $i++;
        }
        $this->write_channel('Done');
        $this->_invalidateWordCaches();
        return;

    }

    private function _setLastPrivmsg($chan = false)
    {
        // initial setup
        if (!$chan) {
            foreach($this->config['channellist'] as $channel => $val) {
                $this->_setChannelData($channel, 'lastPrivmsg', time());
            }
            return;
        }
        if (!($this->_getChannelData($chan, 'lastPrivmsg'))) {
            $this->_setChannelData($chan, 'lastPrivmsg', time());
        }
        $this->_setChannelData($chan, 'lastPrivmsg', time());
        return;
    }

    public function _setBotHandle($nick = false)
    {
        if (!$nick) return;
        $this->config['bothandle'] = $nick;
    }

    public function _getBotHandle()
    {
        return $this->config['bothandle'];
    }

    public function _changeNick($nick = false)
    {
        if (!$nick || !is_string($nick)) return;
        $this->config['_nickChangeTime'] = time() + 30;
        $this->config['_nickChangeString'] = $newnick;

    }

    public function set_arraykey($parts = array())
    {
        if (isset($parts[3])) { // && isset($parts[5])) {
            $this->array_key = $parts[3]; // md5(@$parts[3] . @$parts[5]);
        }
    }

    public function get_arraykey()
    {
        return $this->array_key;
    }

    private function get_userhash($nick = '')
    {
        if (empty($nick)) {
            return false;
        }

        return isset($this->config['usercache'][$nick]) ? $this->config['usercache'][$nick]['userhash'] : false;
    }

    public function _check_acl($data)
    {
        return;
        $action = @$data['command'];

        /*
        $dbgmsg = "Checking ACL action for " . $this->get_current_user() . " -- command: " . @$data['command'];

        $this->Log->log($dbgmsg, 3);
        $datastr = is_array($data) ? json_encode($data, true) : $data;
        $this->Log->log($datastr, 3);
        */

        if (isset($this->config['usercache'][$this->get_current_user()]) && $this->check_current_userhash($this->get_current_user(), $this->get_parts())) {
            $isoper = $this->config['usercache'][$this->get_current_user()]['isoper'];
        } else {
            $isoper = false;
        }

        $count = 0;

        if ($action == 'acl') {
            // check requesting user
            if ($isoper == 1) {
                $count = 1;
            } else {
                try {
                    $random_insult = $this->linguo->get_word('insult');
                    $this->write_user("You're not allowed to do that, ".$random_insult['word'].'.');
                } catch (Exception $e) {
                    $this->Log->log('DB Error', 2);
                }
            }
        } elseif (in_array($action, $this->public_commands)) {
            $count = 1;
        } else {
            // perform the actual user validation against the hash:
            // should be allowed
            $criteria = array(
                'user' => $this->get_userhash($this->get_current_user()),
                'action' => $action,
            );
            try {
                $count = $this->collection->acl->count($criteria);
            } catch (Exception $e) {
                $this->Log->log('DB Error', 2);
            }
        }

        /*
        if ($count == 0) {
            $random_abuse   = $this->linguo->get_abuse();
            $random_insult  = $this->linguo->get_word("insult");
            $this->write_user("You do not have permissions for command '" . $action . "'. Access is denied, " . $random_insult['word'] . ". (" . $random_abuse . ")");
        }
        */
        return $count;
    }

    public function acl($args)
    {
        $uargs = $args['uargs'];
        $types = array('permit', 'deny');
        $action = trim(@$uargs[0]);
        $rule = trim(@$uargs[1]);
        $user = trim(@$uargs[2]);

        if (empty($user) && $action == 'list') {
            $nick = $rule;
        } else {
            $nick = $user;
        }

        $userhash = $this->get_userhash($nick);

        if (!$userhash) {
            $user = false;
        }

        $actions = array();
        $aclres = array();
        try {
            $aclres = $this->collection->acl->find();
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
        $acls = array();

        if ($action == 'list') {
            $actions = array();
            try {
                foreach ($this->collection->acl->find(array('user' => $userhash)) as $row) {
                    $actions[] = $row['action'];
                }
            } catch (Exception $e) {
                $this->Log->log('DB Error', 2);
            }
            $this->write_channel(implode(',', $actions));

            return;
        }
        if (!$user) {
            $this->write_channel('acl [action] [permit|deny] [user]');

            return;
        }
        if (!in_array($rule, $types)) {
            $this->write_channel('acl [action] [permit|deny] [user]');

            return;
        }
        // Check if method actually exists
        if (!method_exists($this, $action)) {
            if (substr($action, 0, 1) == '_') {
                $this->write_channel('Clever, nice try...');

                return;
            }
            $this->write_channel("Method '$action' does not exist.");

            return;
        }
        $acl = array(
            'action' => $action,
            'rule' => $rule,
            'user' => $userhash,
        );

        $criteria = array(
            'user' => $userhash,
            'action' => $action,
        );
        ($rule == 'permit') ? $text = 'granted' : $text = 'denied';
        if ($rule == 'deny') {
            try {
                $this->collection->acl->remove($criteria);
                $this->write_channel("$action $text for $user");
            } catch (Exception $e) {
                $this->Log->log('DB Error', 2);
            }

            return;
        }
        try {
            $this->collection->acl->update($criteria, $acl, array('upsert' => true));
            $this->write_channel("$action $text for $user");
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function _debug($stuff = array())
    {
        $this->write_channel($stuff, $this->config['admin_chan']);
    }

    public function debug($args)
    {
        //switch($args['arg1'])
        $this->write_channel(str_replace("\n", '', print_r($args, 1)));
    }

    /* Log user message statistics by day by user */
    public function stats($data)
    {
        unset($data['_id']);
        // Increment user message count
        $criteria = array('date' => date('Y-m-d'), 'user' => (isset($data['user']) ? $data['user'] : ''));
        if ($data['command'] != 'PRIVMSG') {
            return;
        }
        $data = array(
            '$set' => array(
                'date' => date('Y-m-d'),
                'user' => @$data['user'],
                'channel' => $this->get_current_channel(),
            ),
            '$inc' => array(
                'count' => 1,
            ),
        );
        try {
            $this->collection->stats->update($criteria, $data, array('upsert' => true));
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }
    /* returns current message data string */
    public function get_message_data()
    {
        return $this->message_data;
    }
    /* sets current message data string */
    public function set_message_data($data = '')
    {
        if (!empty($data)) {
            $this->message_data = $data;
        }
    }

    private function get_param_string($command)
    {
        return preg_replace('/'.$command.'/i', '', $this->get_message_data());
    }

    public function check_current_userhash($nick = '', $parts = array())
    {
        if (count($parts) == 0 || empty($nick)) {
            return false;
        }
        if (isset($this->config['usercache'][$nick])) {
            $userdata = Irc::break_hostmask($parts[0]);

            return $this->config['usercache'][$nick]['userhash'] == md5($userdata['nick'].$userdata['host']);
        } else {
            return false;
        }
    }

    /* returns current channel unless called from /msg */
    public function get_current_channel()
    {
        if (!$this->currentchannel) {
            $this->set_current_channel($this->config['default_chan']);
        }

        return ($this->currentchannel == $this->config['irc_handle']) ? $this->get_current_user() : $this->currentchannel;
    }
    /* sets current channel string */
    public function set_current_channel($channel = '')
    {
        if (!empty($channel)) {
            $this->currentchannel = $channel;
        }
    }
    /* returns current user string */
    public function get_current_user()
    {
        return $this->currentuser;
    }
    /* sets current user string */
    public function set_current_user($user = '')
    {
        if (!empty($user)) {
            $this->currentuser = $user;
        }
    }

    public function set_isoper($val = false)
    {
        if (!$val) {
            $this->isIRCOper = $val;
        }
    }

    private function is_chat_text($type = '')
    {
        return ($type == 'PRIVMSG') ? true : false;
    }

    /* Returns <title> of webpage. */
    private function get_site_title($url)
    {
        try {
            //$urlContents = file_get_contents($url);
            $urlContents = $this->curl->simple_get($url);
        } catch (Exception $e) {
            return "Couldn't find the title";
        }
        $dom = new DOMDocument();
        @$dom->loadHTML($urlContents);
        $title = $dom->getElementsByTagName('title');

        return trim(@$title->item(0)->nodeValue);
    }

    public function h($args)
    {
        $total = $this->collection->log->count();
        $rand = rand(0, ($total - 1));
        $result = $this->collection->log->find()->skip($rand)->limit(1);
        foreach ($result as $data) {
            $user = $data['user'];
            $channel = $data['channel'];
            $message = $data['message'];
            $created = $data['time'];
        }
        $created = date($this->config['_dateFormat'], $created);
        $this->write_channel("$created | <$user> : $message");
    }

    public function history($args)
    {
        $limit = 10;
        $ucount = count($args['uargs']) - 1;
        if ($ucount < 1) $ucount = 1;
        if (count($args['uargs']) > 1 && isset($args['uargs'][$ucount])) {
            if (is_numeric($args['uargs'][$ucount])) {
                $limit = $args['uargs'][$ucount];
                if ($limit > 25) {
                    $limit = 25;
                }
            }
        }
        $query = '';
        $queryArr = explode(' ', $args['arg1']);
        $i = 1;
        foreach ($queryArr as $word) {
            $query .= $word . ' ';
            if ($i >= count($queryArr)-1) break;
            $i++;
        }
        $query = trim($query);
        $criteria = array(
            '$and' => array(
                    array('message' => new MongoRegEx('/' . $query . '/i')),
                    array('message' => new MongoRegEx('/^(?!history).+/'))
            )
        );
        
        try {
            $result = $this->collection->log->find($criteria)->limit($limit);
            $result->sort(array('time' => -1));
            if ($result->count() > 0) {
                $this->write_user($result->count() . ' results found for "' . $query . '", showing top ' . $limit . ':');
                $i = 0;
                foreach ($result as $history) {
                    $this->write_user('['.date($this->config['_dateFormat'], $history['time']).'] <'.$history['user'].'> '.$history['message']);
                    if ($i > $limit) {
                        $this->write_user('Results limited to ' . $limit);
                        break;
                    }
                    $i++;
                }
            } else {
                $this->write_channel('Nothing found.');
            }
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
            $this->write_channel('Nothing found.');
        }
    }

    /* Adds an event

        Syntax:
        add_event "My prophetic time of awesomeness" --time "1:20pm January 5th, 2014"

        Note: double quotes (") are required to encapsulate any strings with spaces

    */

    public function add_event($args)
    {
        $time = strtotime(trim(@$args['time']));

        if ($time < time()) {
            $this->write_user('Date ('.$time.') is malformed / in the past, use time (date / time) to get Unix time');

            return false;
        }

        $data = array(
            'when' => @$time,
            'who' => $this->get_current_user(),
            'rsvp' => array(),
            'description' => trim(@$args['arg1']),
        );
        try {
            $c = $this->collection->events->event;
            $c->insert($data);
            $this->write_channel('Event added for '.date('Y-m-d g:i A', $time));
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
            $this->write_channel('Error adding event');
        }

        return true;
    }

    /* Lists upcoming events */
    public function events()
    {
        try {
            $c = $this->collection->events->event;
            $r = $c->find(array('when' => array('$gt' => time())));
            if ($r->count() > 0) {
                $this->write_user('Upcoming Events');
                foreach ($r as $e) {
                    $this->write_user('What : '.$e['description']);
                    $this->write_user('When : '.date('Y-m-d g:i A', (int) $e['when']).' ('.date('l', (int) $e['when']).')');
                    $this->write_user('RSVP : '.implode(', ', @$e['rsvp']));
                    $this->write_user('ID   : '.$e['_id']);
                    $this->write_user('--------------------------');
                }
            } else {
                $this->write_user('Nothing! How boring, do something!');
            }
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
            $this->write_user('Nothing! How boring, do something!');
        }

        return true;
    }

    /* removes an event given an ID passed */
    public function rm_event($args)
    {
        $id = trim($args['arg1']);
        try {
            $c = $this->collection->events->event;
            $r = $c->remove(array('_id' => new MongoId($id)));
            $this->write_user('Event '.$id.' removed.');
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    /* RSVPs to an event given its ID */
    public function rsvp($args)
    {
        try {
            $c = $this->collection->events->event;
            $mid = new MongoId($args['arg1']);
            $criteria = array('_id' => $mid);
            $eventdata = $c->findOne($criteria);
            if (!$eventdata) {
                $this->write_user("Event doesn't exist.");

                return false;
            }
            $data = array('$addToSet' => array('rsvp' => $this->get_current_user()));
            $c->update($criteria, $data, array('upsert' => true));
            $this->write_channel($this->get_current_user().' will be attending '.$eventdata['description']);
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    public function clear($args)
    {
        $this->write_channel('Your screen has been cleared');
    }

    public function time($args)
    {
        $time = trim($this->get_param_string($args['command']));
        if (!is_numeric($time)) {
            if (!strtotime($time)) {
                return;
            }
            $this->write_channel('Unix : '.strtotime($time));
            $this->write_channel('Human : '.date('Y-m-d g:i A', strtotime($time)).' ('.date('l', strtotime($time)).')');
        } else {
            $this->write_channel('Unix : '.$time);
            $this->write_channel('Human : '.date('Y-m-d g:i A', $time).' ('.date('l', $time).')');
        }

        return;
    }

    /* displays the timespan in various units between two given strings (formatting should be strtotime() friendly) */
    /*
        --timespan <from> | <to>
    */
    public function timespan($args)
    {
        $parts = explode('|', $this->get_param_string($args['command']));
        $then = strtotime(trim(@$parts[0]));
        $now = strtotime(trim(@$parts[1]));
        $output = $this->_calculate_timespan($then, $now)."\n";
        $years = round(($now - $then) / 60 / 60 / 24 / 365, 2);
        $days = round(($now - $then) / 60 / 60 / 24, 2);
        $hours = round(($now - $then) / 60 / 60, 2);
        $mins = round(($now - $then) / 60, 2);
        $secs = ($now - $then);
        $trimester = $years * 4;
        $this->write_channel("Years : $years");
        $this->write_channel("Days : $days");
        $this->write_channel("Hours : $hours");
        $this->write_channel("Mins : $mins");
        $this->write_channel("Seconds : $secs");
        $this->write_channel("Trimester : $trimester");
        if ($trimester <= 2) {
            $this->write_channel('* Eligible for abortion!');
        }

        return true;
    }

    public function write_bio($args)
    {
        try {
            $c = $this->collection->bios->bios;
            $criteria = array('user' => $this->get_current_user());
            $data = array('user' => $this->get_current_user(), 'bio' => $this->get_param_string($args['command']));
            $c->update($criteria, $data, array('upsert' => true));
            $this->write_user('Your bio has been saved, '.$this->get_current_user());
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    public function bio($args)
    {
        $user = trim($args['arg1']);
        try {
            $c = $this->collection->bios->bios;
            $criteria = array('user' => $user);
            $data = $c->findOne($criteria);
            $message = (count($data) > 0) ? $user."'s Bio: ".$data['bio'] : 'No bio found for '.$user;
            $this->write_channel($message);
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    public function bios($args)
    {
        try {
            $c = $this->collection->bios->bios;
            $data = $c->find();
            if ($data->count() > 0) {
                foreach ($data as $bio) {
                    $this->write_user($bio['user'].': '.$bio['bio']);
                }
            } else {
                $this->write_user('No bios found!');
            }
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
            $this->write_user('No bios found!');
        }

        return true;
    }

    public function downvote($args)
    {
        try {
            $c = $this->collection->irc->votes;
            $criteria = array('user' => 'nupogodi');
            $data = array('$inc' => array('downvotes' => 1));
            $c->update($criteria, $data, array('upsert' => true));
            $d = $c->findOne($criteria);
            if ($d['downvotes'] % 11 == 0) {
                $this->write_channel('Congratulations! '.$d['user'].' has '.$d['downvotes'].'Congratulations, suck a dick faggot.');

                return true;
            }
            $this->write_channel($d['user'].' has '.$d['downvotes'].' downvotes.');
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    public function butthurt($args)
    {
        $this->write_channel('true');
    }

    public function jimmies($args)
    {
        $this->write_channel('Jimmies status : extremely rustled');
    }

    public function upvote($args)
    {
        if ($this->_check_permissions($this->get_current_user())) {
            $this->write_user("<permission denied> I can't let you do that ".$this->get_current_user());

            return false;
        }
        $in_user = trim($args['arg1']);
        if ($this->get_current_user() == $in_user) {
            $this->write_user("You can't upvote yourself idiot");

            return false;
        }

        if ($in_user == 'sunshine') {
            $this->write_channel('Go to hell');
            
            // I felt bad ...
            //return false;
        }

        if ($in_user == 'nupogodi') {
            $this->write_channel('Get fucked');

            return false;
        }

        try {
            $c = $this->collection->irc->votes;
            $criteria = array('user' => $in_user);
            $data = array('$inc' => array('upvotes' => 1));
            $c->update($criteria, $data, array('upsert' => true));
            $d = $c->findOne($criteria);
            if ($d['upvotes'] % 10 == 0) {
                $this->write_channel('Congratulations! '.$d['user'].' has '.$d['upvotes'].' upvotes and has been awarded a free BJ from pybot.');

                return true;
            }
            $this->write_channel($d['user'].' has '.$d['upvotes'].' upvotes.');
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    public function karma ($args = array())
    {
        if (!isset($args['arg1']) || empty($args['arg1'])) {
            $user = $this->get_current_user();
        } else {
            $user = $args['arg1'];
        }
        $c = $this->collection->irc->votes;
        $criteria = array('user' => $user);

        $data = $c->findOne($criteria);

        if (isset($data['upvotes']) && is_numeric($data['upvotes'])) {
            $str = $user . ' has ' . $data['upvotes'] . ' upvotes.';
        }

        if (isset($data['downvotes']) && is_numeric($data['downvotes'])) {
            $str .= ' ' . $data['downvotes'] . ' downvotes.';
        }

        if (isset($data['assfucks']) && is_numeric($data['assfucks'])) {
            $str .= ' ' . $data['assfucks'] . ' assfucks.';
        }
        
        return $this->write_channel($str);
    }

    public function assfuck($args)
    {
        if ($this->_check_permissions($this->get_current_user())) {
            $this->write_user("<permission denied> I can't let you do that ".$this->get_current_user());

            return false;
        }
        $in_user = trim($args['arg1']);
        if ($this->get_current_user() == $in_user) {
            $this->write_user("You can't assfuck yourself idiot");

            return false;
        }
        try {
            $c = $this->collection->irc->votes;
            $criteria = array('user' => $in_user);
            $data = array('$inc' => array('assfucks' => 1));
            $c->update($criteria, $data, array('upsert' => true));
            $d = $c->findOne($criteria);
            if ($d['assfucks'] % 5 == 0) {
                $this->write_channel('Congratulations! '.$d['user'].' has been assfucked '.$d['assfucks'].' times and is bleeding out the asshole.');

                return true;
            }
            $this->write_channel($d['user'].' has been fucked in the asshole '.$d['assfucks'].' times!');
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    public function radio($args)
    {
        //return;
        $abuse = $this->linguo->get_abuse($args);
        $this->collection->radio->insert(array('text' => $abuse));
    }

    public function sayradio($args)
    {
        //return;
        $message = @$args['arg1'];
        $this->collection->radio->insert(array('text' => $message));
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // VOTING MODULE
    // TODO: break out into module - for now test in here

    // OVERVIEW

    // register
        // user must register to vote

    // nominate
        // add naminated status to user    

    // polls
        // display candidates and running
        // output who is leading
        // order by highest votes

    // vote
        // cannot vote for candidate if not nominated
        // can vote more than once
        // vote limit if hit (e.g' "you can't vote more than 8 times, dumbass! everyone knows that")

    // unvote
        // remove vote from user

    public function check_citizenship()
    {
        // checks user permissions to see if the user is an eligible voter
        if ($this->_check_permissions($this->get_current_user())) {
            $this->write_user("<permission denied> I can't let you do that, ".$this->get_current_user().'. You are not a citizen!');

            return false;
        }

        return true;
    }

    public function checkvoter()
    {
        if (!$this->check_citizenship()) {
            return false;
        }

        // check for voter registration status
        try {
            // get the votes db
            $collection = $this->collection->irc->votes;

            // find the current user in the user list
            $criteria = array('user' => $this->get_current_user());
            $voter = $collection->findOne($criteria);

            $this->write_channel('Voter is: ', print_r($voter));

            // check if the user is registered
            if (isset($voter['registered'])) {
                if ($voter['registered'] == true) {
                    // means the user is registered
                    return true;
                }
            }

            // if we made it this far, the user did not register to vote
            $this->write_channel($this->get_current_user().' bro, do you even register to vote?! (type "register" to sign up to vote)');

            return false;
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function register()
    {
        // check if the user is a citizen ; if not, they cannot register to vote
        if ($this->check_citizenship() == false) {
            return false;
        }

        // the user name
        $in_user = $this->get_current_user();

        try {
            $collection = $this->collection->irc->votes;
            $criteria = array('user' => $in_user);
            $voter = $collection->findOne($criteria);
            // $this->write_channel($in_user . " is the user.");
            $data = array('$inc' => array('registered' => true));
            $collection->update($criteria, $data, array('upsert' => true));
            $this->write_channel($this->get_current_user().' has registered to vote!');

            return true;
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function nominate($args)
    {
        // check if the user CAN vote - cancel if they cannot vote
        if ($this->checkvoter() == false) {
            return false;
        }

        // the user name
        $in_user = trim($args['arg1']);

        try {
            $collection = $this->collection->irc->votes;

            // find the current user in the user list
            $criteria = array('user' => $in_user);
            $candidate = $collection->findOne($criteria);

            // is the user already running
            if (isset($candidate['running'])) {
                if ($candidate['running'] == true) {
                    $this->write_channel($in_user.' is already running in the election.');

                    return false;
                }
            }

            // if not, sign 'em up!    
            $data = array('$inc' => array('running' => true));
            $collection->update($criteria, $data, array('upsert' => true));
            $this->write_channel($this->get_current_user().' has nominated '.$in_user.' as a candidate in the election!');

            return true;
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function vote($args)
    {
        // check if the user CAN vote - cancel if they cannot vote
        if ($this->checkvoter() == false) {
            return false;
        }

        // the user name
        $in_user = trim($args['arg1']);

        // check if voting for self
        if ($this->get_current_user() == $in_user) {
            $this->write_user('Hey look everybody! '.$in_user.' tried to vote for themselves! #Russia');

            return false;
        }

        try {
            // get the votes db
            $collection = $this->collection->irc->votes;

            // find the current user in the user list
            $criteria = array('user' => $in_user);
            $candidate = $collection->findOne($criteria);

            // check if person voted for is running for candidacy
            if (isset($candidate['running'])) {
                if ($candidate['running'] == true) {
                    // means the user is running for candidacy   
                    $data = array('$inc' => array('votes' => 1));
                    $collection->update($criteria, $data, array('upsert' => true));
                    $d = $collection->findOne($criteria);
                    $this->write_channel($d['user'].' has '.$d['upvotes'].' votes.');

                    return true;
                }
            }

            // if we made it this far, the user is NOT running in the election
            $this->write_channel($in_user.' is not running in the election. (type "nominate [user]" to nominate a candidate)');

            return false;
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    // END -  VOTING MODULE
    // ---------------------------------------------------------------------------------------------------------------------

    /* Make pybot say something to $chan */
    /*
      Usage:
    say "message" --chan #chan
    */
    public function say($args)
    {
        return;
        $chan = @$args['chan'];
        if (!$chan) {
            $chan = $this->get_current_channel();
        }
        if (!$chan) {
            $chan = $this->config['default_chan'];
        }

        $message = @$args['arg1'];
        $this->write('PRIVMSG', $chan, "$message");
    }

    /* Tell pybot to /join a $chan */
    public function join($args)
    {
        $chan = false;
        if (isset($args['arg1']) && substr(trim($args['arg1']), 0, 1) === '#') {
            $chan = $args['arg1'];
        }
        if (!$chan) return;
        // check if we're here or not
        if (array_key_exists($chan, $this->config['channellist'])) {
            $word = 'there';
            if ($chan == $this->get_current_channel()) $word = 'here';
            $get_insult = $this->linguo->get_word('insult');
            $insult = $get_insult['word'];

            $this->write_channel("I'm " . $word . " already, " . $insult . "!");
            return;
        }
        $this->write_channel("I'll be over in $chan");
        $this->write('JOIN', $chan);
    }

    public function cycle($args)
    {
        return; // disabling this as it's only useful for testing
        if (strlen($args['arg1']) > 0) return;

        // part
        $this->part(array('arg1' => $this->get_current_channel()));
        sleep(1);
        // join
        $this->join(array('arg1' => $this->get_current_channel()));
    }

    /* Tell pybot to leave a $chan */
    public function part($args)
    {
        if (!isset($args['arg1'])) return;
        $parts = explode(' ', $args['arg1']);
        $chan = false;
        if (isset($parts[0]) && strstr($parts[0], '#')) {
            $chan = $parts[0];
        }

        $partmsg = null;

        if (!$chan) {
            $chan = $this->get_current_channel();
            if (!empty($args['arg1'])) {
                $partmsg = $args['arg1'];
            }
        }

        

        if (!array_key_exists($chan, $this->config['channellist']) || ($chan == $this->config['default_chan'])) {
            return;
        }

        if (is_null($partmsg) && isset($parts[1])) {
            unset($parts[0]);
            $partmsg = join(' ', $parts);
        }

        $oldchan = $this->get_current_channel();
        $this->set_current_channel($chan);
        $get_insult = $this->linguo->get_word('insult');
        $insult = $get_insult['word'];
        $this->write_channel("So long, " . $insult . "s!");
        $this->write('PART', $chan, $partmsg);
        $this->_removeChannel($chan);
        if ($oldchan !== $chan) {
            $this->set_current_channel($oldchan);
        }
    }

    public function _addChannel($channel = false)
    {
        if (!$channel) return;
        if (!array_key_exists($channel, $this->config['channellist'])) {
            $this->config['channellist'][$channel] = 1;
        }
        // ping for MODEs
        $this->write('MODE', $channel);

    }

    public function _removeChannel($channel = false)
    {
        if (!$channel) return;
        if (array_key_exists($channel, $this->config['channellist'])) {
            unset($this->config['channellist'][$channel]);
        }
    }

    public function stat($args)
    {
        return false;
        if (@$args['arg1']) {
            $chan = $args['arg1'];
        } else {
            $chan = $this->config['default_chan'];
        }
        try {
            foreach ($this->collection->stats->find() as $stat) {
                $str = $stat['user'].' : '.$stat['count'];
                $this->write('NOTICE', $this->get_current_channel(), $str);
            }
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function wotd($args)
    {
        $type = @$args['arg1'];
        if (!$type) {
            $this->write_channel('You did not specify a type');

            return;
        }
        try {
            $word = $this->linguo->get_word($type);
            $data['arg1'] = $word['word'];
            $this->define($data);
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function mkword($args)
    {
        $word = trim(@$args['arg1']);
        $tmp = trim(@$args['type']);
        $type = strtolower(preg_replace('/[^A-Za-z]/', '', $tmp));

        if (!$word || !$type) {
            $this->write_channel("mkword <word> --type 'type'");

            return;
        }
        $criteria = array(
            'word' => $word,
            'type' => $type,
        );
        $data = array(
            '$set' => array(
                'word' => $word,
                'type' => $type,
                'user' => $this->get_current_user(),
                'time' => time()
            ),
        );
        try {
            // check if exists first

            $checkdata = $this->collection->words->findOne(array('word' => $word, 'type' => $type));
            
            if (is_array($checkdata)) {
                $get_insult = $this->linguo->get_word('insult');
                $insult = $get_insult['word'];

                $get_exclamation = $this->linguo->get_word('exclamation');
                $exclamation = $get_exclamation['word'];

                return $this->write_channel($exclamation . ', ' . $this->get_current_user() . ' you ' . $insult . ' - that word already exists.');
            }

            $this->collection->words->update($criteria, $data, array('upsert' => true));
            $this->write_channel("$word added as $type");
            $this->_invalidateWordCaches();
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function rmword($args)
    {
        if (!isset($args['arg1'])) return;
        if (!isset($args['type'])) return $this->write_channel('Please supply a type before removing a word');
        $word = trim($args['arg1']);
        $type = trim($args['type']);
        try {
            $this->collection->words->remove(array('word' => $word, 'type' => $type));
            $this->write_channel($word . ' (' . $type . ') removed from dictionary.');
            $this->_invalidateWordCaches();
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function qword($args)
    {
        $word = trim(@$args['arg1']);
        try {
            $data = $this->collection->words->findOne(array('word' => $word));
            if ($data) {
                $word = $data['word'];
                $user = $data['user'];
                $type = $data['type'];
                $time = false;
                if (isset($data['time'])) $time = date($this->config['_dateFormat'], $data['time']);

                $str = "$word ($type) was submitted by $user";
                if ($time) $str .= ' - ' . $time;

                $this->write_channel($str);

                return;
            }
            $this->write_channel("Could not find '$word'.");
        } catch (Exception $e) {
            $this->write_channel("Could not find '$word'.");
            $this->Log->log('DB Error', 2);
        }
    }

    public function mktpl($args)
    {
        $tpl = trim(@$args['arg1']);
        if (!$tpl) {
            $this->write_channel('Missing phrase');
        }
        try {
            $dat = $this->collection->templates->find()->sort(array('id' => -1))->limit(1);
            $iter = current(iterator_to_array($dat));
            $id = $iter['id'] + 1;
            $criteria = array(
                'time' => time(),
                'user' => $this->get_current_user(),
            );
            $data = array(
                '$set' => array(
                    'id' => (int) $id,
                    'template' => $tpl,
                ),
            );
            $this->collection->templates->update($criteria, $data, array('upsert' => true));
            $this->write_channel("Template ID : $id");
            $this->_invalidateTplCache();
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function rmtpl($args)
    {
        $id = intval(trim(@$args['arg1']));
        try {
            $r = $this->collection->templates->remove(array('id' => $id));
            $this->write_channel("Removed template $id - $r");
            $this->_invalidateTplCache();
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function imgbuse($args, $image = '')
    {
        return;
        $last_param = end(explode(' ', $args['arg1']));

        // If image provided, remove it from the args
        if (substr($last_param, 0, 4) == 'http') {
            $parts = explode(' ', $args['arg1']);
            $image = array_pop($parts);
            $args['arg1'] = implode(' ', $parts);
        };

        try {
            $result = $this->linguo->get_abuse($args);
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
        // No args sent, just get a rant
        if (!isset($args['arg1']) || strlen(trim($args['arg1'])) == 0) {
            $result = $this->linguo->get_rant($args);
        };

        // Recurse until length
        if (strlen($result) > 280) {
            return $this->imgbuse($args, $image);
        };

        $url = 'http://riboflav.in/image?text='.urlencode(trim($result))."&image=$image";

        $output = file_get_contents($url);
        $short = $this->_shorten($output);
        $imagedata = file_get_contents($image);
        $filename = '/tmp/'.time().'.jpg';
        file_put_contents($filename, $imagedata);
        $count = $this->twitter->upload($image);
        $this->write_channel("HTTP $count");
        $this->write_channel($short);
    }

    public function twabuse($args)
    {
        try {
            $abuse['arg1'] = $this->linguo->get_abuse($args);
            $this->tweet($abuse);
            $this->write_channel($abuse['arg1']);
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
//		$this->write_channel("Sorri guiz got b& : http://i.imgur.com/FpoAkmz.png");
    }

    public function abes($args)
    {
        $this->abuse($args);
    }

    public function abuse($args)
    {
        if ($this->_getChannelData($this->get_current_channel(), 'allowabuse')) {
            
            if (isset($args['joinabuse']) && $args['joinabuse']) {
                if ($this->_getChannelData($this->get_current_channel(), 'joinabuse')) {
                    $this->write_channel($this->_getAbuse($args));
                }
            } elseif (!isset($args['joinabuse'])) {
                $this->write_channel($this->_getAbuse($args));
            }
        }
    }

    private function _sendRadio($data = array())
    {
        $radioUrl = "https://radio.riboflav.in/"; //"https://radio.riboflav.in:9899/"; //"http://99.255.152.57:10010/"; //"http://radio.riboflav.in:1337/api/v1/library";
        //if (!isset($data['url'])) return;
        try {
            //$this->write_channel($radioUrl . '?' . http_build_query($data));
            $thing = file_get_contents($radioUrl . '?' . http_build_query($data)); //"?url=" . $data['url']);
        } catch (Exception $e) {
            $this->write_channel('Request troubles');
        }
        // $this->write('PRIVMSG', $this->config['admin_chan'], 'Hitting URL ' . $radioUrl);
        // $this->write('PRIVMSG', $this->config['admin_chan'], json_encode($data));
        $options = array('CURLOPT_HTTPHEADER', array('Content-type: application/json'));
        //$thing = $this->curl->simple_post($radioUrl, json_encode($data), $options);
        if (!empty($thing)) $this->write_channel($thing);
    }

    public function rabuse($args)
    {
        $abuse = $this->_getAbuse($args);
        $abuse = preg_replace('/\\r\\n/', ' ', $abuse);
        $abuse = preg_replace('/\\n/', ' ', $abuse);
        $data = array('text' => $abuse);
        $this->_sendRadio($data);
    }

    private function _getAbuse($args)
    {
        try {
            if (!isset($args['arg1']) || strlen(trim($args['arg1'])) == 0) {
                if (isset($args['tpl'])) {
                    $args = array('arg1' => $this->randuser(), 'tpl' => $args['tpl']);
                } else {
                    $args = array('arg1' => $this->randuser());
                }
            }
            $requester = $this->get_current_user();
            if (isset($args['joinabuse'])) {
                $requester = $this->config['bothandle'];
            }
            $this->linguo->setLastRequester($requester);
            $abuse = $this->linguo->get_abuse($args);
            return $abuse;
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    private function randuser()
    {
        return array_rand($this->config['usercache']);
    }

    public function checkUserCache($nick = false)
    {
        if (!$nick) return;

        $criteria = array('user' => $nick);
        $result = $this->collection->irc->usercache->findOne($criteria);
        if (!isset($result['user'])) return false;
        return isset($this->config['usercache'][$nick]);
    }

    public function getUserCache()
    {
        return $this->config['usercache'];
    }

    // @TODO build a "current user cache" as well as historical here
    public function setUserCache($key = false, $data = false)
    {
        if (!$data || !$key) return;

        $key = trim($key);

        if (!isset($this->config['usercache'][$key])) {
            $this->config['usercache'][$key] = $data;
        } else {
            foreach($data as $k => $v) {
                $this->config['usercache'][$key][$k] = $v;
            }
        }
        //add it to the DB
        $criteria = array('user' => $key);
        $data = array('user' => $key, 'data' => $data);
        $this->collection->irc->usercache->update($criteria, $data, array('upsert' => true));
    }

    public function rant($args)
    {
        try {
            $this->linguo->setLastRequester($this->get_current_user());
            $abuse = $this->linguo->get_rant($args);
            if (!$abuse || empty($abuse) || (strlen($abuse) <= 2)) $this->rant($args);
            $this->write_channel($abuse);
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function testtpl($args)
    {
        try {
            $args['arg1'] = str_replace('$who', $this->randuser(), $args['arg1']);
            $args['arg1'] = str_replace('$name', $this->generateName(), $args['arg1']);


            $this->_debug($this->config['usercache']['Flimflam']);
            $this->write_channel($this->linguo->testtpl($args));
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }
    }

    public function types($args)
    {
        try {
            $this->write_user($this->linguo->get_word_types());
        } catch (Exception $e) {
            $this->Log->log('DB Error', 2);
        }

        return true;
    }

    // Pybot Stats
    public function pstats($args)
    {
        try {
            /*** general word stats ***/

            $criteria = array(
                array(
                    '$group' => array(
                        '_id' => array('user' => '$user'),
                        'count' => array('$sum' => 1)
                    )
                ),
                array(
                    '$match' => array(
                        'count' => array('$gte' => 50)
                    )
                )
            );
            
            $data = $this->collection->words->aggregate($criteria);
            $words = array();
            $data = $data['result'];
            foreach ($data as $row) {
                if (!isset($words[$row['_id']['user']])) {
                    $words[$row['_id']['user']] = $row['count'];
                }
            }
            arsort($words);
            $count = $this->collection->words->count();
            $wordtext = $count . " words.";
            $a = 0;
            foreach ($words as $user => $val) {
                $wordtext .= ' (' . $val . ') ' . $user;
                if ($a < count($words)-1) {
                    $wordtext .= ';';
                }
                $a++;
            }
            $this->write_channel($wordtext);

            /*** templates ***/

            $criteria = array(
                array(
                    '$group' => array(
                        '_id' => array('user' => '$user'),
                        'count' => array('$sum' => 1)
                    )
                ),
                array(
                    '$match' => array(
                        'count' => array('$gte' => 5)
                    )
                )
            );
            
            $data = $this->collection->templates->aggregate($criteria);
            $templates = array();
            $data = $data['result'];
            foreach ($data as $row) {
                if (!isset($templates[$row['_id']['user']])) {
                    $templates[$row['_id']['user']] = $row['count'];
                }
            }
            arsort($templates);
 
            $count = $this->collection->templates->count();
            $templatetext = $count . " templates.";
            $a = 0;
            foreach ($templates as $user => $val) {
                $templatetext .= ' (' . $val . ') ' . $user;
                if ($a < count($templates)-1) {
                    $templatetext .= ';';
                }
                $a++;
            }
            $this->write_channel($templatetext);

        } catch (Exception $e) {
            $this->write_channel('Something got fucked up');
        }
    }

    private function _setCacheData($key = false, $data = false)
    {
        if (!$key || !$data) return false;
        $this->_cacheData[$key] = $data;
    }

    private function _getCacheData($key = false)
    {
        if (!$key || !isset($this->_cacheData[$key])) return false;
        return $this->_cacheData[$key]; 
    }

    private function _invalidateWordCaches()
    {
        unset($this->_cacheData['wordtypes']);
    }

    private function _invalidateTplCache()
    {

    }

    private function _setWordStats()
    {
        $criteria = array(
            array(
                '$group' => array(
                    '_id' => array('user' => '$user', 'type' => '$type'),
                    'count' => array('$sum' => 1)
                )
            ),
            array(
                '$match' => array(
                    'count' => array('$gte' => 5)
                )
            )
            
        );
        
        $data = $this->collection->words->aggregate($criteria);
        $wordtypes = array();
        $data = $data['result'];

        // @TODO add total wordtype count
        foreach ($data as $row) {
            if (!isset($wordtypes[$row['_id']['type']])) $wordtypes[$row['_id']['type']] = array();
            $wordtypes[$row['_id']['type']][$row['_id']['user']] = $row['count'];
        }
        
        $types = array();
        $finaldata = array();
        foreach($wordtypes as $type => $row) {
            $types[] = $type;
        }
        asort($types, SORT_FLAG_CASE);

        foreach ($types as $index => $type) {
            $typestats = $wordtypes[$type];
            arsort($typestats);
            $finaldata[$type] = $typestats;
        }
        $this->_setCacheData('wordtypes', $finaldata);

    }

    // get the raw tpl text
    public function showtpl($args)
    {
        if (!isset($args['arg1']) || !is_numeric($args['arg1'])) return;

        $this->write_channel($this->linguo->getTemplateString($args['arg1']));

    }

    public function wordstats($args)
    {
        /*** word types ***/
        $finaldata = $this->_getCacheData('wordtypes');
        if (!$finaldata) {
            $this->_setWordStats();
            $finaldata = $this->_getCacheData('wordtypes');
        }
        
        if (!empty($args['arg1'])) {
            $word = trim($args['arg1']);
            if (isset($finaldata[$word])) {
                $worddata = $finaldata[$word];
                $finaldata = array();
                $finaldata[$word] = $worddata;
            } else {
                $this->write_channel('Not enough stats found for ' . $word);
                return;
            }
        }

        $a = 0;
        $i = 0;
        foreach ($finaldata as $type => $data) {
            $typetext = '';
            if (count($data) > 0) $typetext .= $type . ': ';
            foreach ($data as $user => $val) {
                $typetext .= ' (' . $val . ') ' . $user;
                if ($a < count($data)-1) {
                    $typetext .= ';';
                }
                $a++;
            }
            $this->write_channel($typetext);
            if ($i > 5) break;
            $i++;
        }
    }
    /*
    public function geo($args)
    {
        $addr = trim($args['arg1']);
        if ($addr == '127.0.0.1') {
            $this->write('PRIVMSG', $this->get_current_channel(), 'Fapstation, IL');

            return;
        }
        $result = @geoip_record_by_name($addr);
        $output = '';
        if ($result['city']) {
            $output .= $result['city'].', ';
        }
        if ($result['region']) {
            $output .= $result['region'].'. ';
        }
        if ($result['country_name']) {
            $output .= $result['country_name'];
        }
        $this->write('PRIVMSG', $this->get_current_channel(), "$output");
    }
    */

    private function _get_git_revision()
    {
        return shell_exec('/usr/bin/git rev-parse HEAD');
    }

    public function hup()
    {
        return;
        $this->write_channel('As you command, my lord.');
        @shell_exec('/usr/bin/git pull');
        $this->set_current_channel($this->config['default_chan']);
        $this->version();
        $this->write("QUIT: I'll be right back, folks!", null, null);
        fclose($this->socket);
        sleep(2);
        $path = array(getcwd().'/pybotd');
        if (!pcntl_fork()) {
            pcntl_exec('/usr/bin/php', $path);
        }
        die('Killing parent');
    }

    public function version()
    {
        $lastcommit = explode("\n", $this->_getLastGitCommit());
        if (!empty($lastcommit) || $lastcommit) {
            $lastcommitlink = $this->_shorten('https://github.com/philbarbier/pybot-legacy/commit/' . str_replace('commit ', '', $lastcommit[0]));
            $authorraw = trim(str_replace('Author:', '', $lastcommit[1]));
            $author = substr($authorraw, 0, strpos($authorraw, ' <'));
            $lastcommitdate = trim(str_replace('Date: ', '', $lastcommit[2])); 
            $this->write_channel('Last commit: ' . $lastcommitlink . ' "' . trim($lastcommit[4]) . '" on ' . date($this->config['_dateFormat'], strtotime($lastcommitdate)) . ' (' . $this->_calculate_timespan(strtotime($lastcommitdate)) . ' ago) by ' . $author);
        } 
        $version = trim($this->version);
        $get_insult = $this->linguo->get_word('insult');
        $insult = $get_insult['word'];
        $get_dick = $this->linguo->get_word('dick');
        $dick = str_replace(' ', '-', $get_dick['word']);

        $version = str_replace('$$', $dick, $version);

        $version_string = "pybot (" . $insult . ") version " . $version . " - 'Old Found Glory'";
        $this->write_channel($version_string);
        return;
    }
    # HOLY FUCK THIS WAS INSECURE
    private function _git($args)
    {
        return;
        $command = trim(@$args['uargs'][0]);
        $origin = trim(@$args['uargs'][1]);
        $branch = trim(@$args['uargs'][2]);
        $opts = trim(@$args['uargs'][3]);
        $list = array('reset', 'push', 'pull', 'checkout', 'branch', 'status', 'stash', 'pop', 'tag', 'commit', 'reset');

        if (in_array($command, $list)) {
            $runit = "/usr/bin/git $command $origin $branch $opts";
            $this->write_channel("Executing : $runit");
            $cmd = shell_exec($runit);
            foreach (explode("\n", $cmd) as $line) {
                $this->write_channel(trim($line));
            }
        }
    }

    /* function lmgtfy

        runs the string through Let Me Google That For You

    */

    public function lmgtfy($args)
    {
        $q = urlencode($args['arg1']);
        $base = 'http://lmgtfy.com/?q=';
        $url = $base.$q;
        $result = $this->_shorten($url);
        $this->write_channel($result);
    }

    private function _htmldoit($input)
    {
        if (is_string($input)) {
            $tmp = html_entity_decode(strip_tags($input));
            $re = preg_replace_callback('/(&#[0-9]+;)/', function ($m) { return mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES'); }, $tmp);

            return str_replace('3text', '', $re);
        }
    }

    private function _getDefinition($args)
    {
        if (!$args['arg1']) return array();
        $word = trim((string) $args['arg1']);
        $q = rawurlencode($word);
        $output = array();
        $url = 'http://api.urbandictionary.com/v0/define?term=' . $q;
        $html = file_get_contents($url);
        if ($html) {
            $html = json_decode($html);
            if (isset($html->list[0])) {
                $output['definition'] = $html->list[0]->definition;
                $output['example'] = $html->list[0]->example;
            }
        }
        return $output;
    }

    public function rdefine($args)
    {
        $types = explode(', ', $this->linguo->get_word_types());

        if (isset($args['arg1']) && in_array($args['arg1'], $types)) {
            $word = $this->linguo->get_word($args['arg1']);
            $args['arg1'] = $word['word'];
        }

        if (empty($args['arg1'])) {
            $type = $this->linguo->get_random_word_type();
            $word = $this->linguo->get_word($type);
            $args['arg1'] = $word['word'];
        }

        $def = $this->_getDefinition($args);
        if (isset($def['definition'])) {
            if (strlen($def['definition']) > 512 || strlen($def['example']) > 512) return;
            $this->_sendRadio(array('text' => 'Looking up definition for ' . $args['arg1']));
            
            $definition = preg_replace('/\\r\\n/', ' ', $def['definition']);
            $definition = preg_replace('/\\n/', ' ', $definition);

            $example = preg_replace('/\\r\\n/', ' ', $def['example']);
            $example = preg_replace('/\\n/', ' ', $example);

            $this->_sendRadio(array('text' => $definition));
            $this->_sendRadio(array('text' => $example));
        }
        
    }

    public function define($args)
    {
        if (!empty($args['arg1'])) {
            $word = trim((string) $args['arg1']);
            $this->write_channel("Looking up definition for $word ...\n");
            $def = $this->_getDefinition($args);
            if (isset($def['definition'])) {
                if (strlen($def['definition']) > 512) {
                    $chanstr = 'Definition too long';
                    if (strlen($def['definition']) <= 1024) {
                        $chanstr .= ', see PM';
                        $this->write_channel($chanstr);
                        $this->write_user('Definition: ' . $def['definition']);
                    } else {
                        $chanstr .= '! Too bad, sucker!';
                        $this->write_channel($chanstr);
                    }
                } else {
                    $this->write_channel('Definition: ' . $def['definition']);
                }
                if (strlen($def['example']) > 512) {
                    if (strlen($def['example']) > 1024) {
                        $this->write_user('Example too long!');
                    } elseif (isset($def['example']) && strlen($def['example']) > 5) {
                        $this->write_user('Example: ' . $def['example']);
                    }
                } elseif (isset($def['example']) && strlen($def['example']) > 5) {
                    $this->write_channel('Example: ' . $def['example']);
                }
                return;
            }
            $this->write_channel('No definition found');
        } else {
            $this->write_channel('Please specify a search query.');
        }
    }

    // proper dictionary lookup

    public function wdefine($args = array())
    {
        if (!isset($args['arg1']) || empty($args['arg1'])) return;
        
        $word = strtolower($args['arg1']);

        // maybe one day we'll switch to the OED
        // $apiurl = 'https://od-api.oxforddictionaries.com/api/v1';
        $apiurl = "http://www.dictionaryapi.com/api/v1/references/collegiate/xml/";
        $apiurl .= urlencode($word) . "?key=" . urlencode($this->config['dictionary_apikey']);

        // guh, fucking XML! $insult!
        $doc = file_get_html($apiurl);

        if (!$doc || is_null($doc) || empty($doc)) return;

        try {
            $suggestions = $doc->find('suggestion');
        } catch (Exception $ex) {
            $suggestions = array();
        }

        // if the word isn't found, it'll return suggestions - in this case, handle 'em

        if (count($suggestions) > 0) {
            $str = "Couldn't find that, did you mean: ";
            $suggs = array();
            $i = 0;
            foreach($suggestions as $suggestion) {
                $suggs[] = strip_tags($suggestion);
                $i++;
                if ($i > 25) break;
            }
            $str .= join(', ', $suggs) . '?';
            $this->write_channel($str);
            return;
        }
        
        $pronunciation = $doc->find('pr');
        $word = $doc->find('ew');
        $type = $doc->find('fl');
        

        $def = $doc->find('def');
        if (!isset($def[0])) {
            return $this->write_channel("Something didn't work out there, bud");
        }

        $def = $def[0];
        $num = count($def->find('sn'));

        $heading = '';
        if (isset($word[0]) && isset($type[0]) && isset($pronunciation[0])) {
            $heading = strip_tags($word[0]) . ' (' . strip_tags($type[0]) . ') :: ' . strip_tags($pronunciation[0]);
        }
      
        // this determines if we send to the channel or user
        $deflimit = 3;

        if ($num > $deflimit) $this->write_channel('Sending to PM');
        $num > $deflimit ? $this->write_user($heading) : $this->write_channel($heading);

        // this means that there's only one definition point listed
        if ($num === 0) {
            $this->write_channel(strip_tags($def));
            return;
        }

        $defs = array();

        $outputlimit = 10;
        
        // let's not let it go nuts
        if ($num > $outputlimit) $num = $outputlimit;
        for ($i = 0; $i < $num; $i++) {
            $str = strip_tags($def->find('sn', $i)) . ') ';
            $str .= strip_tags($def->find('ssl', $i)) . ' ';
            $str .= str_replace(':', '', strip_tags($def->find('dt', $i)));
            $num > $deflimit ? $this->write_user($str) : $this->write_channel($str);
        }
        if ($num >= $outputlimit) $this->write_user("There was more data but I'm done, go look it up yourself");
        return;
    }

    private function _getHtmlText($nodes) {
        $definition = '';
        foreach ($nodes as $idx => $node) {
            foreach ($node->_ as $key => $val) {
                if (!is_numeric($val)) {
                    if (is_string($val)) {
                        $definition .= $this->_htmldoit($val);
                    }
                }
            }

            if (is_array($node->find('text'))) {
                foreach($node->find('text') as $num => $textnode) {
                    foreach($textnode->_ as $key => $val) {
                        //$definition .= $this->_htmldoit($val);
                    }
                }
            }
        }

        return $definition;

    }

    public function reverse($args)
    {
        $this->write_channel(strrev($args['arg1']));

        return true;
    }

    public function yell($args)
    {
        $this->write_channel(strtoupper($args['arg1']));

        return true;
    }

    public function whisper($args)
    {
        $this->write_channel(strtolower($args['arg1']));

        return true;
    }

    public function get_chr($args)
    {
        $this->write_channel(chr($args['arg1']));

        return true;
    }

    public function sndex($args)
    {
        $this->write_channel(soundex($args['arg1']));

        return true;
    }

    public function getcrypt($args)
    {
        $this->write_channel(crypt($args['arg1']));

        return true;
    }

    public function len($args)
    {
        $this->write_channel(strlen($args['arg1']));

        return true;
    }

    public function get_sl($args)
    {
        $this->write_channel(strlen($args['arg1']));

        return true;
    }

    public function get_md5($args)
    {
        $this->write_channel(md5($args['arg1']));

        return true;
    }

    public function sep($args)
    {
        $parts = array_map('trim', explode('|', $args['arg1']));
        $sep = '-';
        $str = @$parts[0];
        $sep = @$parts[1];
        $letters = array_map('trim', str_split($str));
        $this->write_channel(implode($sep, $letters));

        return true;
    }

    public function get_uc($args)
    {
        $this->write_channel(urlencode($args['arg1']));

        return true;
    }

    public function to_binary($args)
    {
        $in = $args['arg1'];
        $out = '';
        for ($i = 0, $len = strlen($in); $i < $len; ++$i) {
            $out .= sprintf('%08b', ord($in{$i}));
        }
        $this->write_channel($out);

        return true;
    }
    public function from_binary($args)
    {
        $in = $args['arg1'];
        $out = '';
        $len = strlen($in);
        for ($i = 0; $i < $len; ++$i) {
            $ss = substr($in, $i, 1);
            $bd = bindec(substr($in, $i, 1));
            $out .= chr($bd);
            //print "\nin: " . $in . " len: " . $len . ' bd: ' . $bd . ' ss: ' . $ss; 
            //print "\nhere: " . $out;
        }
        $this->write_channel($out);

        return true;
    }

    public function to_hex($args)
    {
        $string = $args['arg1'];
        $hex = '';
        for ($i = 0; $i < strlen($string); ++$i) {
            $char = $string[$i];
            $o = ord($char);
            $dc = dechex($o);
            //print "\nchar: " . $char . " o: " .$o." dc: " . $dc . " -- hex: " . $hex;
            $hex .= $dc;
        }
        $this->write_channel($hex);

        return true;
    }

    public function from_hex($args)
    {
        $hex = $args['arg1'];
        $string = '';
        for ($i = 0; $i < strlen($hex) - 1; ++$i) {
            $string .= chr(hexdec($hex[$i].$hex[$i + 1]));
        }
        $this->write_channel($string);

        return true;
    }

    public function base($args)
    {
        $parts = explode('|', $args['arg1']);
        $int = trim(@$parts[0]);
        $from = trim(@$parts[1]);
        $to = trim(@$parts[2]);
        if (!$to) {
            $this->write_user("You're doing it wrong <val> | <base-from> | <base-to>");

            return false;
        }

        $this->write_channel(base_convert($int, $from, $to));

        return true;
    }

    public function linkhistory($args) {
        // $output .= "$data->username : $data->title - $data->url - $data->created\n";
        try {
            $result = $this->collection->linkhistory->find();
            if ($result->count() > 0) {
                $result->sort(array('created'=>1)); 
                foreach($result as $history) {
                    $this->write_channel($history['username'] . ": " . $history['title'] . " - " . $history['url'] . " - " . $history['created']);
                }
            } else {
                $this->write_channel("Nothing found.");
            }
        } catch (Exception $e) {
            $this->Log->log("DB Error", 2);
            $this->write_channel("Nothing found.");
        }
        return true;
    }

    public function e164($args)
    {
        $arg = implode('.', array_reverse(str_split(preg_replace('/[^0-9]/', '', $args['arg1'])))).'.in-addr.arpa';
        $this->write_channel($arg);
    }

    public function proto($args)
    {
        $arg = trim($args['arg1']);
        $this->write_channel('TCP : '.getservbyport($arg, 'tcp').' | UDP : '.getservbyport($arg, 'udp'));
    }

    public function host($args)
    {
        $arg = trim($args['arg1']);
        if (empty($arg)) return;
        try {
            $this->write_channel(gethostbyname($arg).' => '.gethostbyaddr($arg));
        } catch (Exception $e) {
            $this->_debug('host() firged');
            $this->_debug($e->getMessage());
            $this->_debug($args);
            return;
        }
    }

    public function follow($args)
    {
        $message = $args['arg1'];
        $count = $this->twitter->follow($message);
        $this->write_channel("HTTP $count");
    }

    public function tweet($args)
    {
        //		$this->write_channel("Sorri guiz got b& : http://i.imgur.com/FpoAkmz.png");
        $message = $args['arg1'];
        $len = strlen($message);
        if ($len > 280) {
            $this->write_channel("Too long bro ($len)");

            return;
        };
        $count = $this->twitter->tweet($message);
        $this->write_channel("HTTP $count");
    }

    /*
    public function me($args)
    {
        $msg = $args['arg1'];
        $chr = chr(1);
        $message = "{$chr}ACTION $msg{$chr}";
        $this->write_channel($message);
    }
    */

    public function nupogodi($args)
    {
        $this->write_channel('nupogodi is a fucking cuntbag');
    }
/*
    public function rfr()
    {
        $track = file_get_contents('https://riboflav.in/rfr/api/ices_current');
        $this->write_channel("Now playing : $track");
    }
*/
    public function skip()
    {
        /*
        $track = file_get_contents('https://riboflav.in/rfr/api/ices_next');
        sleep(2);
        $track = file_get_contents('https://riboflav.in/rfr/api/ices_current');
        $this->write_channel("Skipped, now playing : $track");
        */
        file_get_contents("https://radio.riboflav.in/skip");
        return $this->write_channel('Skipped!');
    }
    
    public function configure()
    {
        $base = 'checking for ';
        $bool = array('yes', 'no');
        $word = $this->linguo->get_word('noun');
        $this->write_channel($base.$word['word'].' ..... yes');
        $word = $this->linguo->get_word('verb');
        $adjective = $this->linguo->get_word('adverb');
        $this->write_channel($base.$word['word']." ..... yes, {$adjective['word']}");
        $word = $this->linguo->get_word('noun');
        $otherword = $this->linguo->get_word('verb');
        $this->write_channel("checking if {$word['word']} supports {$otherword['word']} ..... yes");
        $word = $this->linguo->get_word('verb');
        $this->write_channel("checking whether to {$word['word']} ..... yes");
        $word = $this->linguo->get_word('hole');
        $adjective = $this->linguo->get_word('adjective');
        $this->write_channel("checking whether {$word['word']} is {$adjective['word']}..... yes");
        $word = $this->linguo->get_word('hole');
        $otherword = $this->linguo->get_word('size');
        $this->write_channel("mkdep checking sizeof {$word['word']} ..... {$otherword['word']}");
        $word = $this->linguo->get_word('adverb');
        $this->write_channel($base.'lib-'.$word['word'].' ..... yes');
        $word = $this->linguo->get_word('noun');
        $this->write_channel($base.$word['word'].' ..... no');
        $rand = rand(99999, 9999999);
        $otherword = $this->linguo->get_word('activity');
        $anotherword = $this->linguo->get_word('hole');
        $this->write_channel("ERROR: '/tmp/{$word['word']}/{$anotherword['word']}.c' failed on line $rand near '/* {$otherword['word']}' */");
    }
/*
    public function godmode($args)
    {
        $user = trim(@$args['uargs'][0]);
        $userhash = $this->get_userhash($user);
        if ($action = 'all') {
            $fns = 'help 
            stats 
            history 
            add_event 
            events 
            rm_event 
            rsvp 
            clear 
            time 
            timespan 
            uptime 
            write_bio 
            bio 
            bios 
            downvote 
            upvote 
            assfuck
            rfr
            skip 
            say 
            join 
            part 
            stat 
            wotd 
            mkword 
            rmword 
            qword 
            mktpl 
            rmtpl 
            twabuse 
            abuse 
            testtpl 
            types 
            pstats
            geo 
            hup
            version 
            track 
            lmgtfy 
            define 
            reverse 
            yell 
            whisper 
            get_chr 
            getcrypt 
            get_sl 
            get_md5 
            sep 
            get_uc 
            to_binary 
            from_binary 
            to_hex 
            from_hex 
            base 
            linkhistory 
            tstat 
            tsearch 
            tget 
            ssearch 
            sget 
            sstat 
            host 
            tweet 
            realtime
            rant 
            you 
            me
            smoke';
            foreach (explode("\n", $fns) as $fn) {
                if (empty($fn)) {
                    continue;
                }
                $criteria = array(
                    'user' => $userhash,
                    'action' => $fn,
                );
                $acl = array(
                    'user' => $userhash,
                    'action' => $fn,
                    'rule' => 'permit',
                );
                $this->collection->acl->update($criteria, $acl, array('upsert' => true));
            }
            $this->write_channel("$user achieved God Mode!");
        }
    }
*/
    /* Checks all words for valid url, retreives title ad shortens link */
    private function check_url($words, $channel)
    {
        $url = false;

        if (strstr($words[0], 'ACTION')) {
            // this strips the control character from PRIVMSG ACTION (/me)
            $words[0] = substr($words[0], 1);
            $words[count($words)-1] = substr($words[count($words)-1], 0, strlen($words[count($words)-1])-1);
        }

        foreach ($words as $word) {
            $word = trim($word);// preg_replace('/[:cntrl:]/', '', trim($word));
            if (filter_var($word, FILTER_VALIDATE_URL)) {
                //$this->_debug('here');
                $title = $this->get_site_title($word);
                $title = preg_replace('/[^a-zA-Z0-9\s]/', '', $title);
                $title = preg_replace('!\s+!', ' ', $title);
                $title = preg_replace('/\r|\n/', '', $title);
                if (empty($title)) {
                    $title = 'Untitled ';
                }
                //$this->_debug($title);
                // find a better way to ignore other bots?
                if ($this->get_current_user() == $this->config['bothandle']) return;
                $url = $this->_shorten($word);
                $data = array('username' => $this->get_current_user(), 'title' => $title, 'url' => $url, 'created' => date('d-m-Y g:i A'));
                try {
                    $lh = $this->collection->linkhistory;
                    $lh->insert($data);
                } catch (Exception $e) {
                    $this->Log->log('DB Error', 2);
                }
                $this->write_channel("$title - $url");
            }
        }

        return $url;
    }

    public function _incReloadCount()
    {
        $this->_reloadCount++;
    }

    private function _getReloadCount()
    {
        return $this->_reloadCount;
    }

    /* echo the system uptime */
    public function uptime($args)
    {
        $this->write_channel(trim(shell_exec('uptime')));
        $this->write_channel('Bot was started on: ' . date($this->config['_dateFormat'], $this->config['_starttime']));
        $rc = $this->_getReloadCount();
        $reloads = ($rc > 0) ? ' with ' . $rc . ' reload' : '';
        if (!empty($reloads)) {
            $reloads .= ($rc > 1) ? 's' : '';
        } else {
            $reloads = ' with no reloads so far!';
        }

        $this->write_channel($this->_calculate_timespan($this->config['_starttime']) . ' since our last incident' . $reloads);
        
        if ($uptime = file_get_contents('./uptime')) {
            $this->write_channel('Uptime record: ' . $this->_calculate_timespan(0, $uptime));
        }
        return;
    }

    private function _getLastGitCommit()
    {
        $output = shell_exec("git rev-list --format='medium' --max-count=1 `git rev-parse HEAD`");
        // $output .= "\n" . shell_exec("git log -1 --pretty=%B");
        return $output;
    }

    public function b_shorten($url)
    {
        $uuid = '521f616057018475395133';
        $url = rawurlencode($url);

        $result = file_get_contents("https://coinurl.com/api.php?uuid={$uuid}&url={$url}");

        if ($result == 'error') {
            return false;
        } else {
            return $result;
        }
    }

    private function _shorten($url)
    {
        $encoded = urlencode($url);
        try {
            // $result = file_get_contents(;
            $result = $this->curl->simple_get("http://is.gd/create.php?format=simple&url=$encoded");
        } catch (Exception $e) {
            $result = '';
        }

        return $result;
    }

    public function torstar()
    {
        $this->top_rss('http://www.thestar.com/feeds.topstories.rss', 3);
    }

    public function hn()
    {
        $this->top_rss('https://news.ycombinator.com/rss', 3);
    }

    public function reddit($args)
    {
        if (!isset($args['arg1'])) {
            $args['arg1'] = 'toronto';
        }

        if (strstr($args['arg1'], ' ')) return;
        $this->top_rss('http://www.reddit.com/r/'.$args['arg1'].'.rss', 3);
    }

    private function top_rss($url, $count)
    {
        $data = array();
        
        try {
            $data = json_decode(json_encode(simplexml_load_string(file_get_contents($url))), true);
        } catch (Exception $e) {
        }
        $i = 0;
        if (!isset($data['entry'])) return;
        foreach ($data['entry'] as $item) {
            if ($i <= $count - 1) {
                $title = $item['title'];
                $link = $this->_shorten($item['link']['@attributes']['href']);
                $message = "$title - $link";
                $this->write_channel($message);
            }
            ++$i;
        }
    }

    public function btc_general()
    {
        return;
        $data = json_decode(file_get_contents('http://api.bitcoincharts.com/v1/weighted_prices.json'), 1);
        $day = $data['CAD']['24h'];
        $week = $data['CAD']['7d'];
        $month = $data['CAD']['30d'];
        $this->write_channel("24h : $day");
        $this->write_channel("7d : $week");
        $this->write_channel("30d : $month");
    }

    public function btc($args)
    {
        if (!isset($args['symbol'])) {
            return $this->btc_general();
        }

        $data = json_decode(file_get_contents('http://api.bitcoincharts.com/v1/markets.json'), 1);
        foreach ($data as $symbol) {
            if (strcasecmp($symbol['symbol'], $args['symbol']) == 0) {
                $this->write_channel('Last trade: '.$symbol['close'].' '.$symbol['currency']);

                return;
            }
        }

        $this->write_channel('Symbol not found. Check http://bitcoincharts.com/markets/ for a list of symbol names.');
    }

    public function metar($args)
    {
        if (!isset($args['code'])) {
            $args['code'] = 'CYTZ'; // default to Billy Bishop
        }

        $data = json_decode(file_get_contents('http://api.geonames.org/weatherIcaoJSON?ICAO='.$args['code'].'&username=pybot'), 1);
        if (!isset($data['weatherObservation'])) {
            $this->write_channel('Station code not found or API limit reached.');

            return;
        }

        $weather = $data['weatherObservation'];
        $str = $weather['stationName'].': ';
        
        $str .= $weather['temperature'] . ' degrees with ' . $weather['humidity'] . '% humidity.';

        if (isset($weather['windDirection']) && isset($weather['windSpeed'])) {
            $direction = $this->_getWindDirection($weather['windDirection']);
            $speed = round($weather['windSpeed'] * 1.85);
            $str .= ' Wind from the ' . $direction . ' at ' . $speed . ' km/h.';
        }

        return $this->write_channel($str);
    }

    private function _getWindDirection($degrees = false)
    {
        if (!is_numeric($degrees)) return false;
        $points = array('North', 'North North East', 'North East', 'East North East', 'East', 'East South East', 'South East', 'South South East', 'South', 'South South West', 'South West', 'West South West', 'West', 'West North West', 'North West', 'North North West');

        $pointsector = round($degrees / 22.5);
        if (isset($points[$pointsector])) return $points[$pointsector];

    }

    public function b64e($args)
    {
        $this->write_channel(base64_encode($args['arg1']));
    }

    public function b64d($args)
    {
        $this->write_channel(base64_decode($args['arg1']));
    }

    public function leak($args)
    {
        $search = false; #@$args['arg1'];
        $data = $this->_getRandLine();
        $line = '(' . $data['filename'] . ':' . $data['linenum'] . ') ' . $data['line'];
        $this->write_channel($line);
    }

    private function _getRandLine($search = false)
    {
        $dir = new RecursiveDirectoryIterator('./');
        $files = new RecursiveIteratorIterator($dir);
        $matches = array();
        foreach ($files as $name => $obj) {
            if (substr($name, strlen($name)-4, 4) === '.php') {
                $matches[] = $name;
            }
        }

        $listing = '';
        $listData = array();

        foreach ($matches as $key => $file) {
            $listData[$key]['file'] = substr($file, 2);
            $listData[$key]['contents'] = explode("\n", file_get_contents($file));
        }

        // random file first
        $rand = array_rand($listData);
        $filename = $listData[$rand]['file'];

        $line = rand(0, count($listData[$rand]['contents']));
        $str = trim($listData[$rand]['contents'][$line]);

        if (strlen($str) <= 8) return $this->_getRandLine($search);

        return array(
            'filename' => $filename,
            'linenum' => $line,
            'line' => $str
        );
    }
/*
    public function clrsite()
    {
        $path = '/var/www/p.5kb.us/public/index.html';
        file_put_contents($path, '');
        $this->write_channel('Cleared');
    }

    public function website($args)
    {
        $this->write_channel();
        $path = '/var/www/p.5kb.us/public/index.html';
        file_put_contents($path, $args['arg1'].PHP_EOL, FILE_APPEND);
        $this->write_channel('Added');
    }

    public function clrmysite()
    {
        $user = $this->get_current_user();
        $path = "/var/www/p.5kb.us/public/$user.html";
        file_put_contents($path, '');
        $url = "http://p.5kb.us/$user.html";
        $this->write_channel($url);
    }

    public function mysite($args)
    {
        $this->write_channel();
        $user = $this->get_current_user();
        $path = "/var/www/p.5kb.us/public/$user.html";
        file_put_contents($path, $args['arg1'].PHP_EOL, FILE_APPEND);
        $url = "http://p.5kb.us/$user.html";
        $this->write_channel($url);
    }
*/
    private function generateName($args = null)
    {
        $race = $args['arg1'];
        $races = array('asian', 'black', 'brit', 'indian', 'irish');

        if (!$race) {
            // use a random race
            $race = $races[rand(0, (count($races) - 1))];
        }

        if (!in_array($race, $races)) {
            $output = implode(' ', $races);
            $this->write_channel("Currently available races: $output");

            return;
        }

        // Name Arrays
        $black = array(
            // first name components
            array('La', 'Da', 'Dar', 'Ty', 'Quen', 'Jer', 'Quin', 'Nel', 'Jam', 'Per'),
            array('shar', 'mar', 'rone', 'kwon', 'i', 'ron', 'fer', 'z'),
            array('icious', 'ious', 'us', '-ray-ray', 'da', 'miah', 'ny'),
            // last names 
            array('Jones', 'Smith', 'Williams', 'Robinson', 'Harris', 'Washington', 'Jackson', 'Carter', 'Brown', 'Johnson'),
        );

        $asian = array(
            // first name components
            array('Fo', 'Ho', 'Sum', 'Cho', 'Ling', 'Hao'),
            array('-su', '-chi', '-fu', '-sing', '-lau'),
            // last names 
            array('Lai', 'Lo', 'Minh', 'Chi', 'Han', 'Ling', 'Wang', 'Hong'),
        );

        $indian = array(
            // first name components
            array('San', 'Pra', 'Jay', 'Han', 'Nav', 'Har', 'Sun', 'Chop'),
            array('kar', 'deep', 'ti', 'een', 'a', 'ar', 'il', '-ak'),
            array('man', 'san', 'sin', 'dhi', 'sak', 'ra'),
            // last names 
            array('Singh', 'Gupta', 'Patel', 'Chankar', 'Nahasapheemapetillan'),
        );

        $irish = array(
            // first name components
            array('Shea', 'Ras', 'Ang', 'Bren', 'Dor', 'En', 'Flynn', 'Mae', 'Roe'),
            array('mus', '-lynn', 'gus', '-an', 'nall', 'dal', 'in', 'ovan'),
            // last names 
            array("O'Leary", 'MacGuffin', "O'Sullivan", 'Finnegan'),
        );

        $brit = array(
            // first name components
            array('Stan', 'Brad', 'Rich', 'Chet', 'Will', 'Mart', 'Chad'),
            array('ley', 'ton', 'son', 'ard', 'wick', 'ford'),
            // last names 
            array('Abbotshire', 'Clydesdale', 'Whitwick', 'Chesterton', 'Binghamton', 'Bradford', 'Dickens'),
        );

        // Accepts an array as an argument for a pool of names
        if ($race == 'asian') {
            $race = $asian;
        }
        if ($race == 'black') {
            $race = $black;
        }
        if ($race == 'indian') {
            $race = $indian;
        }
        if ($race == 'irish') {
            $race = $irish;
        }
        if ($race == 'brit') {
            $race = $brit;
        }

        //
        $first_name = '';
        $last_name = '';
        $subarrays = count($race);
        $syllables = rand(2, ($subarrays - 1));

        for ($i = 0; $i < $syllables; ++$i) {
            $first_name .= $race[ $i ][ rand(0, (count($race[$i]) - 1)) ];
        }

        $last_name = $race[$subarrays - 1][ rand(0, (count($race[$subarrays - 1]) - 1)) ];

        // 
        $result = "$first_name $last_name";
        // $this->write_channel($result);
        return $result;
    }

    public function name($args)
    {
        $this->write_channel($this->generateName($args));
    }

    /* can I date */
    public function dateable($args)
    {
        $parts = explode('|', $this->get_param_string($args['command']));

        if (count($parts) < 2) {
            $this->write_channel('You? Never...');

            return;
        }

        $first = intval(trim(@$parts[0]));
        $second = intval(trim(@$parts[1]));

        if ($first >= $second) {
            $higher = $first;
            $lower = $second;
        } else {
            $higher = $second;
            $lower = $first;
        }

        if ($lower < 14) {
            $this->write_channel('Have a seat right over there, please...');

            return;
        }

        if ($lower >= ($higher / 2) + 7) {
            $this->write_channel("It's legit, bro.");
        } else {
            $this->write_channel('Too young! Gross!');
        }
    }

    /* math functions */
    private function _sum($args)
    {
        $parts = explode('|', $this->get_param_string($args['command']));

        if (count($parts) < 2) {
            return;
        }

        $total = 0;

        foreach ($parts as $part) {
            $total += $part;
        }

        return $total;
    }

    public function multiply($args)
    {
        $parts = explode('|', $this->get_param_string($args['command']));

        if (count($parts) < 2) {
            return;
        }

        $total = 1;
        foreach ($parts as $part) {
            $part = trim($part);
            $total *= $part;
        }

        $this->write_channel(round($total, 2));
    }

    public function add($args)
    {
        $this->write_channel($this->_sum($args));
    }

    public function subtract($args = array())
    {
        $parts = explode('|', $args['arg1']);
        if (count($parts) != 2) return;

        if (!is_numeric(trim($parts[0])) || !is_numeric(trim($parts[1]))) return;

        $this->write_channel(($parts[0] - $parts[1]));
    }

    public function divide($args)
    {
        $parts = explode('|', $this->get_param_string($args['command']));

        if (count($parts) < 2) {
            return;
        }

        if ($parts[0] == 0 || $parts[1] == 0) {
            $get_insult = $this->linguo->get_word('insult');
            $insult = $get_insult['word'];
            $this->write_channel("Trying to divide by 0 eh? " . $insult . "!");
            return;
        }

        $parts[0] = trim($parts[0]);
        $parts[1] = trim($parts[1]);

        if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return;
        }

        $total = 0;
        $total = $parts[0] / $parts[1];
        $this->write_channel(round($total, 2));
    }


    /* calculate bmi - first version is in pounds */
    public function bmi($args)
    {
        $parts = explode('|', $this->get_param_string($args['command']));

        if (count($parts) < 2) {
            return;
        }

        $weight = trim(@$parts[0]);
        $height = trim(@$parts[1]);
        $system = trim(@$parts[2]);

        if ($system == 'metric') {
            // convert height to metres
            $height /= 100;
            // weight / squared height
            $bmi = $weight / pow($height, 2);
        } else {
            // check if the height is presented in feet and inches
            $feet_inches = preg_split("/[\',\"]/", $height);

            // were there feet and inches?
            if (count($feet_inches) > 1) {
                // convert the height to inches ( feet * 12 + inches )
                $height = (intval(trim(@$feet_inches[0])) * 12) + trim(@$feet_inches[1]);
            }

            // weight / squared height , all multiplied by 703
            $bmi = ($weight / pow($height, 2)) * 703;
        }

        // prepare the response string
        $response = $bmi.' - ';

        if ($bmi < 0) {
            $response .= 'The_Hatta';
        } elseif ($bmi < 16) {
            $response .= 'severely underweight';
        } elseif ($bmi < 18.5) {
            $response .= 'underweight';
        } elseif ($bmi < 25) {
            $response .= 'normal';
        } elseif ($bmi < 30) {
            $response .= 'overweight';
        } elseif ($bmi < 35) {
            $response .= 'moderately obese';
        } elseif ($bmi < 40) {
            $response .= 'severely obese';
        } else {
            $response .= 'morbidly obese';
        }

        $this->write_channel($response);
    }

    public function dental()
    {
        $this->write_channel('Lisa needs braces');
    }

    public function MORTAL_KOMBAT($args)
    {
        $this->write_channel('                        ..sex..                        ');
        $this->write_channel("                     uuuueeeeeu..^'*&e.                ");
        $this->write_channel("                 ur d&&&&&&&&&&&&&&Nu '*Nu             ");
        $this->write_channel("               d&&& ^&&&&&&&&&&&&&&&&&&e.'&c           ");
        $this->write_channel('           u&&c   ^^   ^^^**&&&&&&&&&&&&&b.^R:         ');
        $this->write_channel('         z&&#^^^           `!?&&&&&&&&&&&&&N.^         ');
        $this->write_channel('       .&P                   ~!R&&&&&&&&&&&&&b         ');
        $this->write_channel("      x&F                 **&b. '^R).&&&&&&&&&&        ");
        $this->write_channel('     J^^                           #&&&&&&&&&&&&.      ');
        $this->write_channel('    z&e                      ..      ^**&&&&&&&&&      ');
        $this->write_channel('   :&P           .        .&&&&&b.    ..  ^  #&&&&     ');
        $this->write_channel('   &&            L          ^*&&&&b   ^      4&&&&L    ');
        $this->write_channel('  4&&            ^u    .e&&&&e.^*&&&N.       @&&&&&    ');
        $this->write_channel('  &&E            d&&&&&&&&&&&&&&L ^&&&&&  mu &&&&&&F   ');
        $this->write_channel('  &&&            &&&&&&&&&&&&&&&&N   ^#* * ?&&&&&&&N   ');
        $this->write_channel("  &&F            '&&&&&&&&&&&&&&&&&bec...z& &&&&&&&&   ");
        $this->write_channel("  &&F             `&&&&&&&&&&&&&&&&&&&&&&&& '&&&&E^&   ");
        $this->write_channel('  &&                  ^^^^^^^`       ^^*&&&& 9&&&&N    ');
        $this->write_channel('  k  u&                                  ^&&. ^&&P r   ');
        $this->write_channel('  4&&&&L                                   ^&. eeeR    ');
        $this->write_channel("   &&&&&k                                   '&e. .@    ");
        $this->write_channel("   3&&&&&b                                   '&&&&     ");
        $this->write_channel('    &&&&&&                                    3&&^     ');
        $this->write_channel('     &&&&&                                    4&F      ');
        $this->write_channel('      RF** <&&                                J^       ');
        $this->write_channel('       #bue&&&LJ&&&Nc.                        ^        ');
        $this->write_channel("        ^&&&&&&&&&&&&&r                      '         ");
        $this->write_channel("          `^*&&&&&&&&&                      '          ");
    }

    public function getcc()
    {
        $arr1 = array(
            'ass',
            'fuck',
            'cock',
            'tampon',
            'shit',
            'penis',
            'juice',
            'ball',
            'jizz',
            'cum',
            'fanny',
            'dick',
            'bitch',
            'douche',
            'fart',
            'nut',
            '\'gina',
            'piss',
            'whore',
            'slut',
            'mother',
            'sissy',
            'wench',
            'crap',
            'nipple',
            'anus',
            'retard',
            'jerk',
            'trash',
            'tit',
            'snot',
            'scum',
            'diaper',
            'granny',
            'pecker',
            'cooch',
            'twat',
            'mouth',
            'rectum',
            'wiener',
            'cunt',
            'fetus',
            'clit',
            'ho',
            'schlong',
            'sack',
            'meat',
            'pussy',
            'testicle',
            'dildo',
            'prick',
            'scrotum',
            'muff',
            'panty',
            'pube',
            'sperm',
            'poop',
            'butt',
            'pork',
            'feces',
            'beef',
            'queef',
            'clown',
        );

        $arr2 = array(
            'chunk',
            'bagger',
            'flaps',
            'licker',
            'munch',
            'rammer',
            'sucker',
            'waffle',
            'eater',
            'face',
            'biter',
            'bucket',
            'monkey',
            'dumpster',
            'jacket',
            'junkie',
            'chomper',
            'lips',
            'fucker',
            'blower',
            'donkey',
            'monster',
            'wad',
            'jockey',
            'dangler',
            'skank',
            'pooper',
            'farm',
            'lover',
            'shitter',
            'hole',
            'pincher',
            'beater',
            'sniffer',
            'wipe',
            'twister',
            'slammer',
            'folds',
            'clot',
            'glob',
            'jammer',
            'fondler',
            'tickler',
            'fungus',
            'plug',
            'packer',
            'wrangler',
            'slime',
            'diddler',
            'sandwich',
            'gobbler',
            'wanker',
            'muncher',
            'stain',
            'boner',
            'nugget',
            'booger',
            'rag',
            'basket',
            'burger',
            'biscuit',
            'bandit',
            'gargler',
        );
        return $arr1[array_rand($arr1)].' '.$arr2[array_rand($arr2)];
    }

    public function cc()
    {
        
        $this->write_channel($this->getcc());
    }

    public function The_Hatta($args)
    {
        if (strlen($args['arg1']) > 0) return;
        $this->write_channel('      _____');
        $this->write_channel(' _____|LI|_\\__');
        $this->write_channel('[    _  [^   _ `)     The_Hatta');
        $this->write_channel('`"""(_)"""""(_)~');
    }
    public function flimflam($args)
    {
        if (strlen($args['arg1']) > 0) return;
        $this->write_channel('     __o');
        $this->write_channel("   _ \<,_");
        $this->write_channel('  (_)/ (_) flimflam');
    }
    public function blafunke($args)
    {
        if (strlen($args['arg1']) > 0) return;
        $this->write_channel('                                                               /|');
        $this->write_channel("                    XYX XYX XYX  ,-.                         .' |");
        $this->write_channel(",-.__________________H___H___H__(___)_____________________,-'   |");
        $this->write_channel('| ||__________________________________________ ___ `._____      |');
        $this->write_channel("`-'   / /           | | | | | |               |   `. \    `-.   |");
        $this->write_channel('     | |            | | _ | | |   ,-.         |    | |       `. |');
        $this->write_channel("     | |    ________| ,'_)| | |__(___)________|_   | |         \|");
        $this->write_channel("     ' \  .' ________).`-.|-| |_______________|_\_.' / |");
        $this->write_channel("      \ `-`._________)|`-'|-| |____________________.'@/");
        $this->write_channel("       `------------|_| |_| |_|-----------------'J `-'    blafunke");
    }

    public function gorf($args)
    {
        if (strlen($args['arg1']) > 0) return;
        $this->write_channel('   (o)--(o) ');
        $this->write_channel("  /.______.\ ");
        $this->write_channel("  \________/ ");
        $this->write_channel(" ./        \.");
        $this->write_channel('( .        , ) ');
        $this->write_channel(" \ \_\\//_/ / ");
        $this->write_channel('  ~~  ~~  ~~ ');
    }

    public function sunshine($args)
    {
        if (strlen($args['arg1']) > 0) return;
        $this->write_channel("                        \     (      /");
        $this->write_channel("                   `.    \     )    /    .'");
        $this->write_channel("                     `.   \   (    /   .'");
        $this->write_channel("                       `.  .-''''-.  .'");
        $this->write_channel("                 `~._    .'/_    _\`.    _.~'");
        $this->write_channel("                     `~ /  / \  / \  \ ~'");
        $this->write_channel("                _ _ _ _|  _\O/  \O/_  |_ _ _ _");
        $this->write_channel("                       | (_)  /\  (_) |");
        $this->write_channel("                    _.~ \  \      /  / ~._");
        $this->write_channel("                 .~'     `. `.__.' .'     `~.");
        $this->write_channel("                       .'  `-,,,,-'  `.");
        $this->write_channel("                     .'   /    )   \   `.");
        $this->write_channel("                   .'    /    (     \    `.");
        $this->write_channel("                        /      )     \     sunshine");
        $this->write_channel('                              (');
    }

    public function home()
    {
        $hour = date('H');
        if (in_array($hour, range(17, 23)) || in_array($hour, range(0, 9))) {
            $this->write_channel("IT'S HOME NOW!!!");

            return;
        }
        $now = time();
        $time = strtotime('5 PM', $now);
        $delta = $time - $now;
        if ($delta < 0) {
            $time = strtotime('tomorrow 5 PM', $now);
        }

        $this->write_channel($this->_nicetime(date($this->config['_dateFormat'], $time)));
    }

    /*
    public function w()
    {
        $data = shell_exec('/usr/bin/w');
        foreach (explode("\n", $data) as $line) {
            $this->write_channel($line);
        }
    }

    public function fortune()
    {
        $out = implode(' ', explode("\n", shell_exec('/usr/games/fortune')));
        $this->write_channel($out);
    }
    */
    public function beer($args = array())
    {
        if (isset($args['arg1']) && strtolower($args['arg1']) == 'beer beer') {
            $this->write_channel('Bed! Bed! Bed!');
        }

        $hour = date('H');
        $dow = date('w');
        if ((in_array($hour, range(15, 23)) || in_array($hour, range(0, 6))) || ($dow == 0 || $dow == 6)) {
            return $this->write_channel("IT'S BEER NOW!!!");
        }

        $now = time();
        $time = strtotime('3 PM', $now);
        $delta = $time - $now;
        if ($delta < 0) {
            if ($dow == 5) {
                return $this->write_channel("IT'S BEER NOW!!!");
            }
            $time = strtotime('tomorrow 3 PM', $now);
        }

        $this->write_channel($this->_nicetime(date($this->config['_dateFormat'], $time)));
    }

    private function _nicetime($date)
    {
        if (empty($date)) {
            return 'No date provided';
        }

        $periods = array('second', 'minute', 'hour', 'day', 'week', 'month', 'year', 'decade');
        $lengths = array('60', '60', '24', '7', '4.35', '12', '10');

        $now = time();
        $unix_date = strtotime($date);

       // check validity of date
    if (empty($unix_date)) {
        return 'Bad date';
    }

    // is it future date or past date
    if ($now > $unix_date) {
        $difference = $now - $unix_date;
        $tense = 'ago';
    } else {
        $difference = $unix_date - $now;
        $tense = 'from now';
    }

        for ($j = 0; $difference >= $lengths[$j] && $j < count($lengths) - 1; ++$j) {
            $difference /= $lengths[$j];
        }

        $difference = round($difference);

        if ($difference != 1) {
            $periods[$j] .= 's';
        }

        return "$difference $periods[$j] {$tense}";
    }

    public function guess($args)
    {
        $uargs = $args['uargs'];
        $who = trim(array_pop($uargs));
        $word = (implode(' ', $uargs));
        $get_insult = $this->linguo->get_word('insult');
        $insult = $get_insult['word'];
        if (!$word && !$who) {
            $this->write_channel("It's : guess <word> <handle>, $insult");
        }
        try {
            $data = $this->collection->words->findOne(array('word' => $word));
            if ($data) {
                $user = $data['user'];
                if ($user == $who) {
                    // Update points correct / incorrect
                    $this->collection->guess->update(
                        array('user' => $this->get_current_user()),
                        array('$inc' => array('correct' => 1)),
                        array('upsert' => true)
                    );
                    $count = $this->collection->guess->findOne(array('user' => $this->get_current_user()));
                    $correct = @$count['correct'];
                    $incorrect = @$count['incorrect'];
                    $this->write_channel("Correct! (c: $correct / i: $incorrect)");

                    return;
                }
                $this->collection->guess->update(
                    array('user' => $this->get_current_user()),
                    array('$inc' => array('incorrect' => 1)),
                    array('upsert' => true)
                );
                // Get guesscount for user
                $count = $this->collection->guess->findOne(array('user' => $this->get_current_user()));
                $correct = @$count['correct'];
                $incorrect = @$count['incorrect'];
                $this->write_channel(strtoupper("Wrong $insult (c:$correct / i:$incorrect)"));

                return;
            }
            $this->write_channel("Could not find word."); 
        } catch (Exception $e) {
            $this->write_channel("Could not find word");
            $this->Log->log('DB Error', 2);
        }
    }

    public function intox($args)
    {
        $this->write_channel('Not enough');
    }

    public function then($args)
    {
        if (is_long($args['arg1'])) {
            $text = gmdate("Y-m-d\TH:i:s\Z", $args['arg1']);
            $this->write_channel($text);
        }
    }

    public function trebek()
    {
        $this->collection->trivia->drop();
        $data = json_decode(file_get_contents('http://jservice.io/api/random'));
        $question = $data[0]->question;
        $category = $data[0]->category->title;
        $answer = $data[0]->answer;
        $value = $data[0]->value;
        $nice = array(
            'question' => $question,
            'category' => $category,
            'answer' => strip_tags($answer),
            'value' => $value,
        );
        if (!$nice['question'] || empty($nice['question'])) {
            return $this->trebek();
        }
        foreach($nice as $key => $item) {
            //$this->write('PRIVMSG', $this->config['admin_chan'], $key . ' => ' . $item); 
        }
        $this->collection->trivia->insert($nice);
        $this->write_channel($question." ($category)");
    }

    public function forfeit()
    {
        $data = $this->collection->trivia->findOne();
        $question = $data['question'];
        $answer = strtolower($data['answer']);
        $this->write_channel($question);
        $this->write_channel('Answer : '.stripslashes($answer));
        $this->write_channel('Next question :');
        $this->trebek();
    }

    public function answer($args)
    {
        $get_insult = $this->linguo->get_word('insult');
        $insult = $get_insult['word'];

        return $this->write_channel("It's 'whatis' now, " . $insult . "!");
    }

    public function whatis($args)
    {
        if (!isset($args['arg1'])) return $this->write_channel('Incorrect');
        

        $answer = strtolower($args['arg1']);

        $data = $this->collection->trivia->findOne();
        $question = $data['question'];
        $value = $data['value'];
        $canswer = strtolower(stripslashes($data['answer']));
        $pattern = '/[^a-z][^A-Z][^0-9]/';
        preg_replace($pattern, '', $canswer);
        preg_replace($pattern, '', $answer);
        if ((strlen($canswer) > 2) && (strlen($answer) <= 2)) return $this->write_channel('Incorrect');
        $who = $this->get_current_user();
        
        if (stristr($canswer,$answer)) {
            $this->write_channel("Correct $who, $value points (" . $canswer . ")");
            $this->write_channel('Next question :');

            // add points!
            $this->_addTriviaPoints($who, $value);

            return $this->trebek();
        }

        return $this->write_channel('Incorrect');
    }

    private function _addTriviaPoints($user = '', $points = 0)
    {
        if (empty($user) || $points <= 0) return;

        // get existing points, if any

        $criteria = array('user' => $user);

        $c = $this->collection->irc->triviascores;
        $data = $c->findOne($criteria);
        if (isset($data['user']) && isset($data['points'])) {
            $newpoints = (int)$data['points'] + (int)$points;
        } else {
            $newpoints = $points;
        }

        $scoredata = array(
            'user' => $user,
            'points' => $newpoints
        );

        $c->update($criteria, $scoredata, array('upsert' => true));
    }

    public function triviascores($args = array())
    {
        $data = $this->collection->irc->triviascores->find();
        foreach($data as $row) {
            $this->write_channel($row['user'] . ": " . $row['points']);
        }
        return;
    }

    public function remind($args)
    {
        $data = $this->collection->trivia->findOne();
        if (!isset($data) || !isset($data['question'])) {
            return $this->trebek();
        }
        $this->write_channel('Last question was:');
        $this->write_channel($data['question'] . ' (' . $data['category'] . ')');

    }

    public function ryt($args)
    {
        $dateFmt = 'l jS \of F Y';
        if (empty($args['arg1'])) {
            $data = $this->_getYoutube($args);
            $url = $origurl = $data['origurl'];
            $who = $data['who'];
            $what = $data['title'];
            $when = date($dateFmt, strtotime($data['when']));
        } else {
            if (stristr($args['arg1'], 'http')) {
                $url = $origurl = $args['arg1'];
                $who = $this->get_current_user();
                $when = date($dateFmt);
                $what = $this->get_site_title($url);
                if ($what == 'YouTube') {
                    $data = $this->_getYoutube();
                    $url = $data['origurl'];
                    $origurl = $data['origurl'];
                    $who = $data['who'];
                    $what = $data['title'];
                    $when = date($dateFmt, strtotime($data['when']));
                }
            } else {
                $data = $this->_getYoutube($args);
                $url = $data['origurl'];
                $origurl = $data['origurl'];
                $who = $data['who'];
                $what = $data['title'];
                $when = date($dateFmt, strtotime($data['when']));
            }
        }

        $now = date('U');

        $urlData = $this->_checkUrlHistory($origurl, $now);

        if ($url != $urlData['url']) {
            $url = $urlData['url'];
            $origurl = $urlData['origurl'];
            $who = $urlData['who'];
            $what = $urlData['title'];
            $when = date($dateFmt, strtotime($urlData['when']));
        }

        // log history
        $data = array(
                'url' => $origurl,
                'who' => $who,
                'when' => $when,
                'title' => $what,
                'timestamp' => $now
            );

        $col = $this->collection->radio->songhistory;
        $criteria = array('url' => $url);

        $col->update($criteria, $data, array('upsert' => true));
        
        $what = str_ireplace('youtube', '', $what);
        $songAnnounce = "And next up from " . $who . " is " . $what . ' ' . $url; // . " which was played on " . $when;
        $this->write_channel($songAnnounce);
        $this->_sendRadio(array('text' => $songAnnounce));
        $extras = array('url' => $origurl, 'user' => $who, 'token' => md5($origurl), 'intro_text' => $songAnnounce);
        $thing = $this->_sendRadio($extras);
    }

    private function _checkUrlHistory($url, $now)
    {
        $urlData = false;
        $col = $this->collection->radio->songhistory;
        $criteria = array('url' => $url);
        $history = $col->find($criteria); 

        $threshold = 21600; //86400;
        foreach ($history as $record) {
            if (($now - $record['timestamp']) <= $threshold) {
                $urlData = $this->_getYoutube();        
                $url = $this->_checkUrlHistory($urlData['origurl'], $now);
            }
        }

        if (is_array($urlData)) {
            if ($urlData['title'] == 'YouTube') {
                $urlData = $this->_getYoutube();
                $url = $this->_checkUrlHistory($urlData['origurl'], $now);
            }
        }

        return is_array($urlData) ? $urlData : array('url' => $url); 
    }

    public function yt($args)
    {
        $data = $this->_getYoutube($args);
        if ((!isset($data['title']) || !isset($data['url'])) || (empty($data['title']) || empty($data['url']))) return $this->write_channel('Nothing found');
        if (empty($data['title'])) $this->_debug($data);
        $msg = $data['title'] . " | " . $data['url'] . " | " . $data['who'] . " on " . $data['when'];
        $this->write_channel($msg);
    }

    private function _getYoutube($args = array())
    {
        $criteria = array(
            '$or' => array(
                array(
                    'message' => array(
                    '$regex' => new MongoRegex('/youtube.com/i'),
                    ),
                ),
                array(
                    'message' => array(
                        '$regex' => new MongoRegex('/youtu.be/i'),
                    ),
                ),
            ),
        );
        $query = (isset($args['arg1']) && !empty($args['arg1'])) ? $args['arg1'] : false;
        if ($query) {
            $criteria['user'] = $args['arg1'];
        };
        $count = $this->collection->log->count($criteria);
        $rand = rand(0, $count);
        $data = $this->collection->log->find($criteria)->skip($rand)->limit(1);
        foreach ($data as $record) {
            $string = $record['message'];
            preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $string, $match);
            $url = @$match[0][0];
            $title = $this->get_site_title($url);
            if (empty($url)) $this->_getYoutube($args);
            if ($title == 'YouTube') {
                $this->_cleanUrlHistory($record['_id'], $url);
                if (isset($record['urltitle'])) {
                    $this->write_channel("Video for " . $record['urltitle'] . " could not be found");
                }
                return $this->_getYoutube($args);
            }
            if (!isset($record['urltitle'])) $this->_addUrlTitle($record['_id'], $url, $title);
            $origurl = $url;
            $url = $this->_shorten($url);
            $when = date($this->config['_dateFormat'], (int) $record['time']);
            $who = $record['user'];
            $this->_logYoutube($origurl, $who, $when);
            return array('url' => $url, 'title' => $title, 'when' => $when, 'who' => $who, 'origurl' => $origurl);
        }
    }

    private function _addUrlTitle($objId, $url = false, $title = false)
    {
        if (!$url) return;

        if (!$title) $title = $this->get_site_title($url);

        $criteria = array('_id' => new MongoId($objId));
        $record = $this->collection->log->findOne($criteria);
        
        if (!isset($record['_id'])) return;
        
        $record['urltitle'] = $title;
        unset($record['_id']);
        $this->collection->log->update($criteria, $record, array('upsert' => true));
    }

    private function _cleanUrlHistory($objId = false, $url = false)
    {
        if (!$objId) return;
        
        $criteria = array('_id' => new MongoId($objId));
        $record = $this->collection->log->findOne($criteria);
       
        if (!isset($record['message'])) return;

        $record['message'] = str_replace($url, '<old video URL removed>', $record['message']);
        unset($record['_id']);
        $this->collection->log->update($criteria, $record, array('upsert' => true));
    }

    public function itg($args)
    {
        $this->write_channel('What the fuck did you just fucking say about me, you little bitch? I’ll have you know I graduated top of my class in the Navy Seals, and I’ve been involved in numerous secret raids on Al-Quaeda, and I have over 300 confirmed kills. I am trained in gorilla warfare and I’m the top sniper in the entire US armed forces. You are nothing to me but just another target. I will wipe you the fuck out with precision the likes of which has never been seen before on this Earth, mark my fucking words. You think you can get away with saying that shit to me over the Internet? Think again, fucker. As we speak I am contacting my secret network of spies across the USA and your IP is being traced right now so you better prepare for the storm, maggot. The storm that wipes out the pathetic little thing you call your life. You’re fucking dead, kid. I can be anywhere, anytime, and I can kill you in over seven hundred ways, and that’s just with my bare hands. Not only am I extensively trained in unarmed combat, but I have access to the entire arsenal of the United States Marine Corps and I will use it to its full extent to wipe your miserable ass off the face of the continent, you little shit. If only you could have known what unholy retribution your little “clever” comment was about to bring down upon you, maybe you would have held your fucking tongue. But you couldn’t, you didn’t, and now you’re paying the price, you goddamn idiot. I will shit fury all over you and you will drown in it. You’re fucking dead, kiddo.');
    }
    /*
    public function lightson($args)
    {
        $this->write_channel(shell_exec('/usr/local/bin/lightson'));
    }
    public function lightsoff($args)
    {
        $this->write_channel(shell_exec('/usr/local/bin/lightsoff'));
    }
    */
    public function said($args)
    {
        $criteria = array();
        $when = false;
        if ($args['arg1']) {
            $criteria = array('user' => $args['arg1']);
        };
        $count = $this->collection->log->count($criteria);
        $rand = rand(0, $count);
        $data = $this->collection->log->find($criteria)->skip($rand)->limit(1);
        foreach ($data as $msg) {
            $user = $msg['user'];
            $mesg = $msg['message'];
            $when = date($this->config['_dateFormat'], (int) $msg['time']);
        };
        if (!$when) return;
        $this->write_channel("($when) $user> $mesg");
    }

    public function rhost($args)
    {
        $addr = rand(1, 254).'.'.rand(1, 254).'.'.rand(1, 254).'.'.rand(1, 254);
        $this->write_channel(gethostbyname($addr).' => '.gethostbyaddr($addr));
    }
    public function rmn($args)
    {
        $arg = array('deflects ','attacks ','concedes to ','redirects to ', 'is cucked by ');
        $out = $arg[array_rand($arg)];
        $this->write_channel("Roman $out" . $this->randuser());
    }

    private function _getUserSmokes($user = false)
    {
        if (!$user) return;

        $user = trim($user);
        if (strstr($user, ' ')) {
            return;
        }
        
        $smokedata = $this->_getLastSmoke($user);
        if (!$smokedata) {
            $this->write_channel('No smoke data for ' . $user . ' found');
            return;
        }
        $message = 'Smoke stats for ' . $user;
        $this->write_channel($message);
        $message = 'Total of ' . $smokedata['totalsmokes'] . ' smokes';
        $message .= ' since ' . date($this->config['_dateFormat'], $smokedata['firstsmoke']);
        $message .= '. The last smoke was ' . $smokedata['time'] . ' - ' . $smokedata['ago'] . ' ago.';
        return $message;
    }

    public function smokes($args)
    {
        $c = $this->collection->irc->smokecount;
        $data = $c->find();
        $smokes = array();
        foreach($data as $row) {
            if (!isset($smokes[$row['user']])) {
                $smokes[$row['user']] = array();
            }
            $smokedata = $this->_getLastSmoke($row['user']);
            $smokes[$row['user']]['smokecount'] = $smokedata['totalsmokes'];
            $smokes[$row['user']]['firstsmoke'] = $smokedata['firstsmoke'];
            $numdays = floor((date('U') - $smokedata['firstsmoke']) / 86400);
            if ($numdays > 0) $smokes[$row['user']]['average'] = ceil($smokedata['totalsmokes'] / $numdays);

        }
        if (count($smokes) > 4) {
            $this->write_channel('Data will be sent via PM');
            foreach($smokes as $user => $smoke) {
                $this->write_user($user . ' has smoked ' . $smoke['smokecount'] . ' total smokes, averaging ' . $smoke['average'] . ' a day since ' . date($this->config['_dateFormat'], $smoke['firstsmoke']));
            }
        } else {
            foreach($smokes as $user => $smoke) {
                $this->write_channel($user . ' has smoked ' . $smoke['smokecount'] . ' total smokes, averaging ' . $smoke['average'] . ' a day since ' . date($this->config['_dateFormat'], $smoke['firstsmoke']));
            }
        }
    }

    public function smoke($args)
    {
        $user = $this->get_current_user();

        if (isset($args['arg1']) && !empty($args['arg1'])) {
            $this->write_channel($this->_getUserSmokes($args['arg1']));
            return;
        }

        $c = $this->collection->irc->smokecount;
        $criteria = array('user' => $user, 'day' => date('d'), 'month' => date('m'), 'year' => date('Y'));
        $d = $c->findOne($criteria);
        $newsmokes = 1;
        $lastsmoke = $this->_getLastSmoke();
        if (isset($d['smokes'])) {
            $newsmokes = (int)$d['smokes'] + 1;
        }
        $data = array(
            // '$inc' => array('smokes' => 1), 
            'user' => $this->get_current_user(),
            'smokes' => $newsmokes,
            'day' => date('d'), 
            'month' => date('m'), 
            'year' => date('Y'),
            'time' => time()
        );
        $c->update($criteria, $data, array('upsert' => true));
        $d = $c->findOne($criteria);
        $firstsmoke = '';
        $totalsmokes = isset($lastsmoke['totalsmokes']) ? $lastsmoke['totalsmokes'] : '1'; 
        if ($lastsmoke && isset($lastsmoke['firstsmoke'])) {
            $firstsmoke = ' since ' . date($this->config['_dateFormat'], $lastsmoke['firstsmoke']); 
        }
        $response = "That's smoke #" . $d['smokes'] . " for " . $d['user'] . " so far today... This brings you to a grand total of " . $totalsmokes . " smoke" . (($totalsmokes > 1) ? "s": "") . $firstsmoke . ". Keep up killing yourself with cancer!";
        if ($lastsmoke && isset($lastsmoke['time'])) $response .= ' Your last smoke was at ' . $lastsmoke['time'] . " - about " . $lastsmoke['ago'] . " ago";
        
        $this->write_channel($response);
    }

    private function _getLastSmoke($user = false)
    {
        if (!$user) $user = $this->get_current_user();

        $allrecords = $this->collection->irc->smokecount->find(array('user' => $user));
        // 1 because this value is used in the smoke() function, so this 
        // accounts for that current smoke
        $totalsmokes = 1;
        $firstsmoke = false;
        foreach($allrecords as $record) {
            if(isset($record['time'])) {
                $smoketime = (int)$record['time'];
                if (!$firstsmoke) $firstsmoke = $smoketime;
                if ($firstsmoke > $smoketime) $firstsmoke = $smoketime;
                $totalsmokes = $totalsmokes + (int)$record['smokes'];
            }
        }
        unset($record);
        $total = $allrecords->count();
        $d2 = $this->collection->irc->smokecount->find(array('user' => $user))->sort(array('time' => -1))->limit(1);
        $lastsmoke = false;
        foreach($d2 as $record) {
            if (isset($record['time'])) {
                $lastsmoke = array();
                $lastsmoke['time'] = date($this->config['_dateFormat'], $record['time']);
                $lastsmoke['totalsmokes'] = $totalsmokes;
                $lastsmoke['ago'] = $this->_calculate_timespan($record['time']);
            }
        }
        if (is_array($lastsmoke)) $lastsmoke['firstsmoke'] = $firstsmoke;
        return $lastsmoke;
    }

    public function lastsmoke($args)
    {
        $lastsmoke = false;
        // $criteria = array('user' => $this->get_current_user(), 'day' => date('d'), 'month' => date('m'), 'year' => date('Y'));
        // $d = $c->findOne($criteria);
        $lastsmoke = $this->_getLastSmoke();
        if ($lastsmoke) $this->write_channel("Well " . $this->get_current_user() . ", this is when you last inhaled cancer " . $lastsmoke['time'] . " - about " . $lastsmoke['ago'] . " ago"); 

    }

    public function lenny($args)
    {
        $this->write_channel("( ͡° ͜ʖ ͡°)");
    }

    public function bart($args)
    {
        $things = array('Go to hell!', 'Kiss my butt!', 'Shut up!');
        $thing = rand(0,count($things)-1);
        $this->write_channel($things[$thing]);
        if (($this->bartUseCount % 5) == 0) {
            $this->write_channel('Hey, once again, great present, Dad.');
        }
        $this->bartUseCount++;
    }

    public function lasttpl($args)
    {
        $data = $this->linguo->getLastTpl($args);

        if (isset($data['no_user'])) {
                $this->write_channel('Last template used was ID ' . $data['tpl_id'] . ' (Created by ' . $data['tpl_user'] . ' on ' . date($this->config['_dateFormat'], $data['tpl_time']) . ') used by ' . $data['user'] . ' on ' . date($this->config['_dateFormat'], ($data['timestamp'])));
                return;
        }
        
        $this->write_channel('The last template ' . $data['user'] . ' used was ID ' . $data['tpl_id'] . ' (Created by ' . $data['tpl_user'] . ' on ' . date($this->config['_dateFormat'], $data['tpl_time']) . ') on ' . date($this->config['_dateFormat'], ($data['timestamp'])));
    }

    public function mlb($args)
    {
        $data = json_decode(file_get_contents("http://www.sportsnet.ca/wp-content/themes/sportsnet/zones/ajax-scoreboard.php"));
        $mlb = false; 
        try {
            $mlb = isset($data->data->mlb) ? $data->data->mlb : false;
        } catch (Exception $e) {
            return;
        }

        if (!isset($mlb)) {
            return;
        }

        $c = 0;
        $inprog = array();

        if (isset($mlb->{'In-Progress'})) {
            foreach ($mlb->{'In-Progress'} as $game) {
                $stat = $game->period_status;
                $ht = $game->home_team_city." ".$game->home_team;
                $vt = $game->visiting_team_city." ".$game->visiting_team;
                $vs = $game->visiting_score;
                $hs = $game->home_score;
                $inprog[] = "In progress : $vt @ $ht $vs-$hs, $stat";
                $c++;
            }
        }

        if ($c > 3) {
            foreach ($inprog as $k => $v) {
                $this->write_user($v);
            }
        } else {
            foreach ($inprog as $k => $v) {
                $this->write_channel($v);
            }
        }

        

        if (!isset($mlb->{'Final'})) {
            return;
        }

        if (isset($args['arg1']) && $args['arg1'] == 'final') {
            foreach ($mlb->{'Final'} as $game) {
                $stat = $game->period_status;
                $ht = $game->home_team_city." ".$game->home_team;
                $vt = $game->visiting_team_city." ".$game->visiting_team;
                $vs = $game->visiting_score;
                $hs = $game->home_score;
                $this->write_user("Final : $vt @ $ht $vs-$hs, $stat");
            }
        } else {
            $this->write_user("You can add 'final' to this command to get all final results");
        }
    }

    public function lookup($args = array())
    {
        if (!isset($args['arg1'])) return;
        $offset = (isset($args['offset']) && is_numeric($args['offset'])) ? $args['offset'] : false;
        $username = trim(@$args['username']);
        $type = trim(@$args['type']);
        $criteria = array(
			'word' => new MongoRegEx('/'.$args['arg1'].'/i')
		);
		if ($type) {
			$criteria['type'] = $type;
		}
		if ($username) {
			$criteria['user'] = $username;
		}

        $results = $this->collection->words->find($criteria); //->limit(5);
        $count = $results->count();

        if ($offset && (($offset > 0) && ($offset < $count))) {
            $results = $results->skip($offset);
        }

        $results = $results->limit(5);        

        foreach ($results as $result) {
            $word = $result['word'];
            $user = $result['user'];
            $type = $result['type'];
            
            $str = "$word ($type) was submitted by $user";
            if (isset($result['time'])) $str .= ' - ' . date($this->config['_dateFormat'], $result['time']);

            $this->write_channel($str);
        }
        return $this->write_channel($count . ' results in total.');
        if (!isset($str)) $this->write_channel('None found');
        return;
    }

    public function lookuptpl($args = array())
    {
        if (!isset($args['arg1'])) return;
        $offset = (isset($args['offset']) && is_numeric($args['offset'])) ? $args['offset'] : false;

        $username = trim(@$args['username']);

        // if we're looking for a var, escape that dorra dorra, yo
        $args['arg1'] = str_replace('$', '\\$', $args['arg1']);

        $criteria = array(
			'template' => new MongoRegEx('/'.$args['arg1'].'/i')
		);
		if ($username) {
			$criteria['user'] = $username;
		}

        $results = $this->collection->templates->find($criteria);
        $count = $results->count();

        if ($offset && (($offset > 0) && ($offset < $count))) {
            $results = $results->skip($offset);
        }
        
        $results = $results->limit(5);

        foreach ($results as $result) {
            $tpl = $result['template'];
            $user = $result['user'];
            $id = $result['id'];
            $idstr = '';
            if (is_numeric($id)) $idstr = " (ID: " . $id . ") "; 
            $str = $tpl . " -" . $idstr . " was submitted by " . $user;
            if (isset($result['time'])) $str .= ' - ' . date($this->config['_dateFormat'], $result['time']);

            $this->write_channel($str);
        }
        return $this->write_channel($count . ' results in total.');
        if (!isset($str)) $this->write_channel('None found');
        return;
    }


    // reminder for the current MC server seed
    public function mcseed($args = array())
    {
        $this->write_channel('-3253466048537712650');
    }

    // because why not?
    public function shrug($args = array())
    {
        return $this->write_channel('¯\_(ツ)_/¯');
    }

    // lulz
    public function stone($args = array())
    {
        if (!isset($args['arg1']) || empty($args['arg1'])) return;

        if ($args['arg1'] == 'cold') {
            $this->write_channel('*GUT KICK*');
            sleep(1);
            $this->write_channel('*STUNNER*');
            sleep(1);
            $this->write_channel('..|.. ..|..');
        }
        return;
    }

    public function channels($args = array())
    {
        if ($this->get_current_channel() != $this->config['admin_chan']) return;
        if (isset($args['arg1']) && !empty($args['arg1'])) return; 
        $this->write_channel($this->config['channellist']);
    }

    public function shinty($args = array())
    {
        if (isset($args['arg1']) && ($args['arg1'] == '6' || $args['arg1'] == 'six')) {
            $this->write_channel("That's numberwang!");
        }
        return;
    }

    public function mkkickword($args = array())
    {
        if (!isset($args['arg1'])) return;
        if (!isset($args['channel'])) {
            $args['channel'] = $this->get_current_channel();
        }
        $criteria = array(
            'user' => $this->get_current_user(),
            'word' => $args['arg1'],
            'channel' => $args['channel'],
        );

        $data = array(
            'user' => $this->get_current_user(),
            'timestamp' => date('U'),
            'word' => $args['arg1'],
            'channel' => $args['channel'],
        );

        $c = $this->collection->irc->kickwords;
        
        $checkdata = $c->findOne(array('word' => $args['arg1'], 'channel' => $args['channel']));
            
        if (is_array($checkdata)) {
            $get_insult = $this->linguo->get_word('insult');
            $insult = $get_insult['word'];

            $get_exclamation = $this->linguo->get_word('exclamation');
            $exclamation = $get_exclamation['word'];

            return $this->write_channel($exclamation . ', ' . $this->get_current_user() . ' you ' . $insult . ' - that word already exists.');
        }
        $c->update($criteria, $data, array('upsert' => true));
        $this->write_channel('Kickword added for ' . $args['channel']);
        $this->_setKickWordCache();
    }

    public function lskickwords($args = array())
    {
        $data = $this->_getCacheData('kickwords'); 
        if (count($data) == 0) {
            $this->_setKickWordCache();
            $data = $this->_getCacheData('kickwords'); 
        }
        if (!isset($data[$this->get_current_channel()]) || empty($data[$this->get_current_channel()])) return $this->write_channel('None found'); 
        return $this->write_channel('(' . $this->get_current_channel() . ') ' . $data[$this->get_current_channel()]);
    }

    public function rmkickword($args = array())
    {
        if (!isset($args['arg1'])) return false;
        if (!isset($args['channel'])) {
            $channel = $this->get_current_channel();
        } else {
            $channel = $args['channel'];
        }
        $criteria = array('word' => $args['arg1'], 'channel' => $channel);
        $res = $this->collection->irc->kickwords->remove($criteria);
        $this->write_channel($args['arg1'] . ' removed for ' . $channel);
        $this->_setKickWordCache();
    }

    private function _setKickWordCache()
    {
        $data = array();
        foreach ($this->config['channellist'] as $channel => $val) {
            $results = $this->collection->irc->kickwords->find(array('channel' => $channel));
            $words = array();
            if ($results->count() > 0) {
                foreach($results as $row) {
                    $words[] = $row['word'];
                }
            }
            if (!isset($data[$channel])) $data[$channel] = array();
            $data[$channel] = implode(', ', $words);
        }
        $this->_setCacheData('kickwords', $data);
    }

    private function _checkKickWord($message = false, $channel = false)
    {
        if (!$message || !$channel) return false;

        $message = str_replace('$', '\\$', $message);
        $words = explode(' ', $message);
        $data = $this->_getCacheData('kickwords');
        if (!isset($data[$channel]) || (count($data[$channel]) == 0) || empty($data[$channel])) return false;
        // @TODO fix the issue of "buzzfeed.com/blah" not matching
        foreach ($words as $word) {
            if (strlen($word) <= 2) continue;
            // if the word is a domain, strip the scheme and path off
            if (stristr($word, 'http')) {
                $parts = parse_url($word);
                if (isset($parts['host'])) $word = $parts['host'];
            }
            $word = preg_replace('/[^a-zA-Z\.]/', '', $word);
            // maybe a preg_match?
            if (stristr($word, $data[$channel])) return $word;
        }
        return false;
    }

	private function _kickwords($args = array()) {
        if (!isset($args['message']) || !isset($args['channel']) || !isset($args['user'])) return;
       
		$channel = $args['channel'];
        if ($this->_getChannelData($channel, 'allowkickwords') === false) return; 
		$message = $args['message'];
		$user = $args['user'];
		$res = $this->_checkKickWord($message, $channel);
        if ($res) {
            $random_insult = $this->linguo->get_word('insult');
            $this->write('KICK', $channel . ' ' . $user, 'STAHP saying "' . $res . '" ' . $random_insult['word']);
            $this->abuse(array('arg1' => $user)); 
		}
	}

    public function ls($args = array())
    {
        $top = $this->linguo->testtpl(array('arg1' => rand(8192,16384) . ' total'));
        $this->write_channel($top);
        $num = rand(1,5);
        for ($i = 0; $i < $num; $i++) {
            $dornot = array('-', 'd');
            $perms = $dornot[array_rand($dornot)];
            $permarray = array('-', 'r', 'w', 'x'); //, 'X', 's', 't');
            for ($j=0; $j < 9; $j++) {
                $perms .= $permarray[array_rand($permarray)];
            }

            $filedate = date('M j H:i', strtotime(rand(1,12) . '/' . rand(1,31) . '/1984 ' . rand(0,23) . ':' . rand(0,59)));
            $owner = strtolower($this->randuser());
            $group = strtolower($this->randuser());
            $this->write_channel($this->linguo->testtpl(array('arg1' => $perms . '   ' . rand(1,16384) . ' ' . $owner . ' ' . $group . ' ' . $filedate . '  $media$extension')));
        }
    }

    // eh why not? :D
    private function _christmas($args = array())
    {
        return;
       
        if (!is_null($this->christmasDate)) return;

        $timeleft = abs(round((strtotime(date('F j')) - strtotime('December 25')) / 60 / 60 / 24, 0));
        if ($timeleft > 0) {
            $str = "\x02\x0303,01C\x0304,01h\x0303,01r\x0304,01i\x0303,01s";
            $str .= "\x0304,01t\x0303,01m\x0304,01a\x0303,01s";
            $str .= " \x0304,01C\x0303,01o\x0304,01u\x0303,01n";
            $str .= "\x0304,01t\x0303,01d\x0304,01o\x0303,01w\x0304,01n\x02";
            $str .= "\x0F";

            $days = 'days';
            if ($timeleft == 1) $days = 'day';
            $str .= "\x02: " . $timeleft . " " . $days . "!\x02";
            $this->christmasDate = date('U');
            $channel = $this->config['default_chan']; // $this->get_current_channel();
            $this->_setTopic($channel, $str);
            return $this->write_channel($str, $channel);
        }
    }

}
