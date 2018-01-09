<?php

class Irc 
{

    public static $config;

    public function __construct($config)
    {
        $this->_classes = false;
        self::$config = $config;
        self::$config['_ircClassName'] = __CLASS__;
        self::$config['_callee'] = array(__CLASS__);
        // lib/
        $this->_loadModules(self::$config['_cwd'] . '/lib');
        // modules/
        $this->_loadModules(self::$config['_cwd'] . '/modules');
        $this->Log = new Log(self::$config);
        $this->server = $config['irc_server'];
        $this->version = $config['versionNum'] . '.' . $config['versionString'];
        $this->port = $config['irc_port'];
        $this->channels = $config['irc_channels'];
        $this->handle = $config['irc_handle'];
        $this->first_connect = true;
        $this->set_socket($this->server, $this->port);
        $this->actions = new Actions(self::$config);
        $this->bleacher = new Bleacher(self::$config);
        $this->_methods = $config['_methods'];
        $this->connect_complete = false;
        $this->retrieve_nick = false;
        $this->arb_counter = 0;
        $this->in_whois = false;
        $this->is_oper = false;
        $this->is_connected = false;
        $this->current_nick = '';
        $this->main();
    }

    public function __destruct()
    {
        // echo 'destructing class ' . __CLASS__ . "\n";
    }

    public static function setCallList($className = false, $list = false)
    {
        if (!$list || !$className) return;
        //echo "SETTING CALLLIST FOR " . $className . "\n";
        self::$config['_classes'][$className]['calllist'] = $list;
    }

    public function initActions($className = false, $config = false)
    {
        if (!$className || !$config) return;
        $this->actions = new $className($config);
    }

    public function initBleacher($className = false, $config = false)
    {
        if (!$className || !$config) return;
        $this->bleacher = new $className($config);
    }


    private function _loadModules($_module_path = false)
    {
        if (!$_module_path) {
            die("Something bad happened -EPATHNOTSET");
        }

        $mod_files = scandir($_module_path);

        if (!$mod_files) {
            die("Something bad happened -ENOFILES");
        }

        foreach($mod_files as $file) {
            switch ($file) {
                case '.':
                case '..':
                case 'irc.php':
                    break;
                default:
                    // reading is good
                    if (is_readable($_module_path . '/' . $file)) {
                        $pathinfo = pathinfo($_module_path . '/' . $file);

                        if (strtolower($pathinfo['extension']) == 'php') {
                            $expected_class = ucfirst(substr($file, 0, strpos($file, '.')));
                            $sample = file_get_contents($_module_path . '/' . $file, NULL, NULL, 0, 1024);

                            $res = false;
                            if (substr($sample, 0, 5) === '<?php') {
                                if (stristr($sample, 'class ' . $expected_class)) {
                                    if (class_exists($expected_class)) {
                                        // we can't re-include if it already exists
                                        // we have to use the reload mechanism
                                        $this->_reloadModules();
                                        return;
                                    }

                                    $res = include $_module_path . '/' . $file;
                                    $newClass = array(
                                        'filename'  => $file, 
                                        'classname' => $expected_class,
                                        'origclass' => $expected_class,
                                        'md5sum'    => md5_file($_module_path . '/' . $file),
                                        'directory' => $_module_path
                                    );

                                    self::$config['_classes'][$expected_class] = $newClass; 
                                    // array_push($config['_classes'], $expected_class);
                                    // if (!isset($config['_methods'][$expected_class])) $config['_methods'][$expected_class] = array();
                                    // $config['_methods'][$expected_class] = get_class_methods($expected_class);
                                }                    
                            }

                            // here's where we can log a success or not
                            if ($res && class_exists($expected_class)) {
                                //echo "Loading module " . $file . " (class " . $expected_class . ") was a success!\n";
                            }
                        }
                        
                    } else {
                        echo "can't read " . $_module_path . $file . "\n";
                    }

            }
        }
    }

    private function _reloadModules($force = false)
    {
        $id = date('U');
        $classes = self::$config['_classes'];

        foreach ($classes as $className => $classData) {
            // if Irc is changed, we'll restart the whole lot
            // it's in the class list because of the reason
            if ($className == 'Irc') continue;
            
            $file = $classData['directory'] . '/' . $classData['filename'];
            $md5 = md5_file($file);
            // echo "checking " . $file . "\n";
            if (!array_key_exists($className, self::$config['_classes'])) {
                // just include it?
            } else {
                if (($md5 != self::$config['_classes'][$className]['md5sum']) || ($force)) {
                    // changed, reload
                    $pathinfo = pathinfo($file);
                    $newClassName = $className . $id;
                    // echo "changed, reloading " . $className . "\n";
                    // read file and change class definition to have temp $id
                    $fileStr = file_get_contents($file);
                    //echo "orig:\n" . $fileStr . "\n";
                    $fileStr = str_replace('class ' . $className, 'class ' . $newClassName, $fileStr);
                    //echo "\nnew:\n".$fileStr."\n";
                    // write to temp file
                    $newFileName = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . $id . '.' . $pathinfo['extension'];
                    $fh = fopen($newFileName, 'w');
                    if (fwrite($fh, $fileStr)) {
                        include $newFileName;
                        unlink($newFileName);
                        
                        //echo "\n" . $className;
                        self::$config['_classes'][$className]['classname'] = $newClassName;
                        self::$config['_classes'][$className]['md5sum'] = $md5;
                        self::$config['_origclass'] = $className;
                        
                        $calllist = self::$config['_classes'][$className]['calllist'];
                        $localRef = strtolower($calllist[count($calllist)-1]);
                        $theirRef = strtolower($classData['origclass']);
                        $initFnName = 'init' . $theirRef;
                        $classconfig = self::$config;
                        if (count($calllist) > 1) {
                            unset($this->$localRef->$theirRef);
                            $this->$localRef->initModule($newClassName, $classconfig);
                        } else {
                            if (method_exists($this->$theirRef, '_getConfig')) {
                                $oldconfig = $this->$theirRef->_getConfig();
                                foreach ($oldconfig as $configkey => $configval) {
                                    // don't override the defaults
                                    if (!isset($classconfig[$configkey])) {
                                        $classconfig[$configkey] = $configval;
                                    }
                                }
                                                           }
                            unset($this->$theirRef);
                            $this->$initFnName($newClassName, $classconfig);
                        }
                    }
                }
            }
        }
    }

    private function set_socket($svr = '', $port = 0)
    {
        if (empty($svr) || $port <= 0) {
            return false;
        }
        $this->Log->log("Connecting to $svr @ $port", 1);
        try {
            $this->socket = fsockopen($svr, $port, $errno, $errmsg);
            $this->Log->log('Socket connection: '.$errmsg, 3);
        } catch (Exception $e) {
            $this->Log->log("Connection error: " . $e->getMessage(), 2);
        }
        if (!$this->socket) {
            $this->destroy_socket();
            sleep(10);
            $this->__construct($this->config);
        }
    }

    public function destroy_socket()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->socket = false;
        $this->first_connect = true;
        $this->connect_complete = false;
        $this->retrieve_nick = false;
        $this->arb_counter = 0;
        $this->in_whois = false;
        $this->is_oper = false;
        $this->is_connected = false;
        $this->Log->log('Disconnecting and closing socket', 1);
    }

    private function set_nickname($nick = '')
    {
        if (empty($nick)) {
            return false;
        }
        $this->Log->log("Setting nick to '$nick'", 3);
        $this->_write("NICK $nick");
        $this->actions->_setBotHandle($nick);
        $this->current_nick = $nick;
    }

    private function get_newnick()
    {
        $newnick = $this->actions->linguo->get_word($this->actions->linguo->get_random_word_type());
        $nick = preg_replace('/[^a-zA-Z0-9\-\_\.\,\+\!\?]+/', '_', $newnick['word']);
        if (strlen($nick > 30)) {
            $this->get_newnick();
        }

        return $nick;
    }

    public function main()
    {
        $this->set_nickname($this->handle);
        $this->_write("USER $this->handle 8 *  :$this->handle");

        if (!$this->socket) {
            $this->Log->log('Socket error', 2);
            $this->destroy_socket();
            sleep(10);
            $this->__construct($this->config);
        }

        while (!feof($this->socket)) {
            $raw = $this->read();
            $this->actions->setsocket($this->socket);
            if (isset($raw) && !empty($raw)) {
                $msg = $this->parse_raw($raw);

                // Print message debug to stdout
                if (self::$config['debug']) {
                    if (isset($msg['message']) && isset($msg['channel']) && isset($msg['user'])) {
                        $this->Log->log($msg['user'].'@'.str_replace('#', '', $msg['channel']).' : '.$msg['message']);
                    }
                }
                $this->actions->catchall($msg);
                $params = false;
                if (isset($msg['message'])) {
                    $params = @$this->parse_command($msg['message']);
                }
                if (!$params) {
                    continue;
                }
                $a = false;
                if (isset($params['command'])) {
                    $a = $params['command'];
                }
                if (!$a) {
                    continue;
                }

                // handle reloads
                if ($a === 'reload' && in_array($msg['user'], array('Flimflam'))) {
                    $config = $this->actions->_getConfig();
                    self::$config['irc_channels'] = $config['irc_channels'];
                    $changed = $this->_rehash();
                    $this->_reloadModules($changed);
                }

                // $this->Log->log("Params: " . json_encode($params, true), 3);
                try {
                    if ((substr($a, 0, 1) != '_') && ($msg['command'] == 'PRIVMSG')) {
                        //
                        $ref = new ReflectionMethod($this->actions, $a);
                        if ($ref->isPublic()) {
                            $result = $this->actions->$a($params);
                            $this->_write($result);
                        }
                    }
                } catch (Exception $e) {

                }

                /* THIS IS ANNOYING AS FUCK!
                if (!$this->actions->_check_acl($params)) {
                    // Log the offender ?
                    // $this->admin_message("Command denial: " . @implode(' ', $params));
                } else {
                    if (method_exists($this->actions, $a)) {
                        $result = $this->actions->$a($params);
                        $this->_write($result);
                    }
                }
                // always reset this
                */
                $this->actions->set_isoper(false);
            }
        }
    }

    public function command($params)
    {
        $module = $this->module;
        $message = $params['message'];
        $parts = explode(' ', $message);
        $command = @$parts[0];
        if (method_exists($module, $command)) {
            return $module->$command($params);
        }
    }

    public function whois($nick = '')
    {
        if (empty($nick)) {
            return false;
        }
        // reset each call
        $this->is_oper = false;
        $this->_write('WHOIS '.$nick);
    }

    private function get_arraykey($parts)
    {
        return md5(@$parts[3].@$parts[5]);
    }

    public function parse_raw($str)
    {
        //echo "\n" . date('Y-m-d H:i:s') . " -- RX: " . $str;
        $matches = null;

        $regex = '{
          :([^!]++)!
          ([^\s]++)\s++
          ([^\s]++)\s++
          :?+([^\s]++)\s*+
          (?:[:+-]++(.*+))? 
        }x';

        preg_match($regex, $str, $matches);
        $parts = explode(' ', $str);

        $result = null;

        if ($parts[0] == 'PING') {
            $this->_write('PONG '.$parts[1]);
            $result = $parts;
            $result['command'] = $parts[0];
        }

        if ($parts[0] == 'ERROR') {
            $this->destroy_socket();
            sleep(10);
            $this->__construct(self::$config);
        }

        $this->actions->set_parts($parts);

        if (!empty($matches)) {
            $result = array(
                'user' => trim(@$matches[1]),
                'command' => trim(@$matches[3]),
                'channel' => trim(@$matches[4]),
                'message' => trim(@$matches[5]),
                'time' => time(),
            );
            $this->actions->set_current_user(trim(@$matches[1]));
            $this->actions->set_current_channel(trim(@$matches[4]));
            $this->actions->set_message_data(trim(@$matches[5]));
        }
        
        if (isset($parts[1]) && !empty($parts[1])) {
        
        // && (is_numeric($parts[1]) || $parts[1] == 'JOIN' || $parts[1] == 'PART' || $parts[1] == 'INVITE' || $parts[1] == 'KILL')) {

            // we should maybe parse for every code here, that way
            // we're able to tell the IRC state better
            $channel = false;
            if (isset($parts[3])) $channel = $parts[3];
            switch (strtoupper($parts[1])) {
                // check WHOIS
                case 311:
                    /*
                    echo "\n=============\n";
                    print_r($parts);
                    echo "\n=============\n";
                    */
                    $this->set_usercache($parts);
                    $this->in_whois = true;
                break;
                // checks WHOISOPERATOR
                case 313:
                    $this->is_oper = true;
                    $array_key = $this->actions->get_arraykey($parts);
                    $data = array(
                        'userhash' => md5(@$parts[3].@$parts[5]),
                        'isoper' => 0
                    );

                    $this->actions->setUserCache($array_key, $data);
                break;
                // checks end of WHOIS
                case 318:
                    $this->in_whois = false;
                break;

                // channel modes that we get back post join MODE call
                case 324:
                    $mode = trim($parts[4]);
                    $user = trim($parts[2]);
                    $channel = trim($parts[3]);
                    //echo "\n" . $channel . ' => ' . $mode;
                    $this->actions->_setChannelData($channel, 'channelmodes', $mode);
                break;
                // channel mode timestamp
                case 329:

                break;

                // topic text
                case 332:
                    if (!$channel) break;
                    $topic = str_replace(':', '', $parts[4]) . ' ';
                    for ($i = 5; $i < count($parts); $i++) {
                        $topic .= $parts[$i] . ' ';
                    }
                    $this->actions->_setChannelData($channel, 'topictext', trim($topic));
                break;
                // topic stats
                case 333:
                    if (!$channel) break;
                    $this->actions->_setChannelData($channel, 'topicdate', trim($parts[5]));
                    $this->actions->_setChannelData($channel, 'topicauthor', trim($parts[4]));
                break;
                // names list
                case 353:
                    foreach ($parts as $i => $val) {
                        if ($i > 4) {
                            $nick = $this->bleacher->ircnick($val);
                            if (!$this->actions->checkUserCache($nick)) {
                                $this->whois($nick);
                            }
                        }
                    }
                    $this->actions->_addChannel($parts[4]);
                break;
                // end of names list
                case 366:
                    // build cache
                break;
                
                // we check here for MOTD/end of MOTD on join
                case 422:
                case 376:
                    //ready to join
                    $this->first_connect = false;
                    $this->connect_complete = true;
                break;
                case 412:
                    // no text to send
                    // echo "\n" . $str;
                break;
                
                // change nick
                case 432:
                case 433:
                    $this->retrieve_nick = true;
                    $newnick = $this->get_newnick();
                    $this->set_nickname($newnick);
                break;
               
                // changing nick too fast!
                case 438:
                    $newnick = $this->actions->_getBotHandle();
                    $this->actions->_changeNick($newnick); 
                break;

                case 473:
                    // invite only channel - remove channel from list?
                break;
                // don't have channel ops
                case 482:
                    $this->admin_message("I don't have ops in " . trim($channel) . " for something I'm trying to do");
                break;


                // non-numerics

                
                case "INVITE":
                    if (isset($parts[3])) {
                        $this->_write('JOIN ' . $parts[3]);
                    }
                break;

                // someone joined
                case 'JOIN':
                    $userinfo = $this->break_hostmask($parts[0]);
                    $this->whois($userinfo['nick']);
                break;

                // we got kicked out? shiiiiiiiiit $insult!
                case "KICK":
                    if (trim($parts[3]) != $this->current_nick) break;
                    $channel = trim($matches[4]);
                    $user = trim($matches[1]);
                    $this->_write("JOIN $channel");
                    $this->actions->write_channel('Hey fuck you, ' . $user . '!', $channel);
                    $this->actions->abuse(array('arg1' => $user));
                break;

                case "KILL":
                    $this->destroy_socket();
                    sleep(15);
                    $this->__construct(self::$config);
                break;

                // mode changes
                case 'MODE':
                    if (count($parts) > 4) {
                        if (strlen($parts[4]) === 2) {
                            $channel = trim($parts[2]);
                            $mode = trim($parts[3]);
                            $oldmode = $this->actions->_getChannelData($channel, 'channelmodes');
                            $chanmode = $this->actions->_getChannelData($channel, 'modes');

                            if ($mode[0] == '-') {
                                //$newmode = str_replace(substr($mode, 1), '', $oldmode);
                                $newmode = $oldmode;
                                for ($i = 0; $i < strlen($mode); $i++) {
                                    if (isset($mode[$i+1]) && strstr($oldmode, $mode[$i+1])) {
                                        $newmode = str_replace($mode[$i+1], '', $newmode);
                                    }
                                }
                            } elseif ($mode[0] == '+') {
                                if (!$this->actions->_hasSameLetters($oldmode, $mode)) {
                                    $newmode = $oldmode . str_replace('+', '', $mode);
                                }
                            }

                            $this->actions->_setChannelData($channel, 'channelmodes', $newmode);
                        }

                        if ($parts[4] === $this->current_nick && isset($newmode)) {
                            $this->actions->_setChannelData($channel, 'botmodes', $newmode);
                        }
                    }
                break;  

                case "NICK":
                    $nickparts = $this->break_hostmask($parts[0]);
                    $data = array(
                        'userhash' => md5(@$nickparts['nick'].@$nickparts['host']),
                        'isoper' => 0
                    );

                    $nick = str_replace(':', '', $parts[2]);
                    $this->actions->setUserCache($nick, $data);
                break;
                // someone left the channel
                case 'PART':

                break;
                // someone changed the topic
                case 'TOPIC':
                    if (!$channel) break;
                    $channel = $matches[4];
                    $this->actions->_setChannelData($channel, 'topictext', trim($matches[5]));
                    $this->actions->_setChannelData($channel, 'topicauthor', trim($matches[1]));
                    $this->actions->_setChannelData($channel, 'topicdate', time());
                    $this->actions->linguo->setLastRequester($this->actions->get_current_user());
                break;
            }
            if (!$this->in_whois) {
                $this->actions->set_isoper($this->is_oper);
                // $this->admin_message($this->actions->get_current_user() . " results: is_oper-local: " . $this->is_oper . " -- is_oper: " . $this->actions->get_isoper());
            }
        }

        if ($this->connect_complete) {
            foreach (self::$config['custom_commands'] as $cmd) {
                $this->_write($cmd);
            }

            foreach ($this->channels as $channel => $channeldata) {
                $this->_write("JOIN $channel");
            }
            // ensure we're in the default channel
            if (!in_array(self::$config['default_chan'], $this->channels)) {
                $this->_write('JOIN '.self::$config['default_chan']);
            }
            // ensure we're in the admin channel
            if (!in_array(self::$config['admin_chan'], $this->channels)) {
                $this->_write('JOIN '.self::$config['admin_chan']);
            }

            // print out current revision
            #$this->admin_message("Loading pybot version : " . $this->version);
            if ($this->retrieve_nick) {
                $this->admin_message('Actively trying to retrieve nick ('.$this->handle.')');
            }
            $this->connect_complete = false;
            $this->is_connected = true;
        }

        // check if we have our normal nick, if not try and get it back
        if (($this->retrieve_nick && !$this->first_connect) && ($this->arb_counter % 50) == 0) {
            $this->admin_message('Attempting to re-gain use of nick...');
            $this->set_nickname($this->handle);
            // at this point, assume it worked
            $this->retrieve_nick = false;
        }

        
        ++$this->arb_counter;

        // arbitrary counter used to do above modulus, resets to ensure bot longevity (<3 pybot)
        if ($this->arb_counter > 10000) {
            $this->arb_counter = 0;
        }
        return $result;
    }

    private function is_chat_text($type = '')
    {
        return ($type == 'PRIVMSG') ? true : false;
    }

    private function set_usercache($parts = array())
    {
        if (count($parts) == 0) {
            return false;
        }

        if ($this->is_connected) {
            $this->actions->set_arraykey($parts);
            $array_key = $this->actions->get_arraykey();
            if (!empty($array_key)) {

                $data = array(
                    'userhash' => md5(@$parts[3].@$parts[5]),
                    'isoper' => 0
                );

                $this->actions->setUserCache($array_key, $data);
               
            }
        }
    }

    public function read()
    {
        if ($this->socket) {
            return fgets($this->socket, 1024);
        } else {
            return false;
        }
    }

    public function get_usercache()
    {
        return $this->actions->getUserCache();
    }

    public function admin_message($message = '')
    {
        if (empty($message)) {
            return false;
        }
        if (is_array($message)) {
            foreach($message as $k => $v) {
                $str = 'PRIVMSG ' . self::$config['admin_chan'] . ' :';
                $str .= $k . ' => ' . $v; 
                $this->_write($str);
            }
            return;
        }
        $this->_write('PRIVMSG ' . self::$config['admin_chan'] . ' :' . $message);
    }

    // re-read config file
    private function _rehash()
    {
        $changed = false;
        $config_path = self::$config['_configPath'];
        if (isset($config_path) && (file_exists($config_path) && is_readable($config_path))) {
            $md5 = md5_file($config_path);
            if (self::$config['_configChecksum'] != $md5) {
                include $config_path;
                // re-read config file data into existing class config
                foreach ($config as $key => $value) {
                    if (!is_array($value)) {
                        self::$config[$key] = $value;
                    } else {
                        foreach($value as $k => $v) {
                            if (is_array($v)) {
                                foreach ($v as $k2 => $v2) {
                                    self::$config[$key][$k][$k2] = $v2;
                                }
                            } else {
                                self::$config[$key][$k] = $v;
                            }
                        }
                    }
                }
                self::$config['_configChecksum'] = $md5;
                $changed = true;
            }
        }

        // check VERSION file for updates
        if (md5(file_get_contents('VERSION')) != self::$config['_versionChecksum']) {
            $v = file_get_contents('VERSION');
            self::$config['versionString'] = $v;
            self::$config['_versionChecksum'] = md5($v);
            $changed = true;
        }

        return $changed;
    }

    public function break_hostmask($hm = '')
    {
        if (empty($hm) || !is_string($hm)) {
            return false;
        }

        $data = array();

        list($first, $second) = explode('@', $hm);
        list($dirtynick, $ident) = explode('!', $first);

        $data['nick'] = $this->bleacher->ircnick($dirtynick);
        $data['ident'] = $ident;
        $data['host'] = $second;

        return $data;
    }

    public function _write($message)
    {
        // echo "\n" . date('Y-m-d H:i:s') . ' -- TX: ' . $message;
        
        if (!$this->socket) {
            $this->Log->log('Socket error', 2);
            self::destroy_socket();
            sleep(10);
            $this->__construct(self::$config);

            return false;
        }
        
        try {
            $res = fwrite($this->socket, $message."\r\n\r\n", strlen($message."\r\n\r\n"));
            if ($res === 0) {
                $res = false;
                $this->Log->log("Couldn't write to the socket", 3);
                //$this->destroy_socket();
                sleep(10);
                $this->__construct(self::$config);
            }
        } catch (Exception $e) {
            $res = false;
            $this->Log->log("Couldn't write to the socket", 3);
            $this->Log->log($e->getMessage());
            $this->destroy_socket();
            sleep(10);
            $this->__construct(self::$config);
        }

        return $res;
    }

    public function parse_command($str)
    {
        // get command name
        $matches = array();
        $matched = preg_match('/^\S+/', $str, $matches);
        $command = $matches[0];

        // get raw and unnamed args
        $matched = preg_match_all('/\s+((\"[^\"]*\")|(\S+))/', $str, $matches, PREG_SET_ORDER);

        $unnamed_args = array();
        $found_named = false;
        foreach ($matches as $match) {
            if (preg_match('/^--/', $match[1]) || $found_named) {
                $found_named = !$found_named;
                continue;
            }
            array_push($unnamed_args, str_replace('"', '', $match[1]));
        }
        $arg1 = str_replace('"', '', $matches[3]);
        // get named args
        $matches = array();
        $matched = preg_match_all('/--(\S+)\s+((\"[^\"]*\")|([^\"]\S*))/', $str, $matches, PREG_SET_ORDER);
        $named_args = array();

        foreach ($matches as $match) {
            $named_args[$match[1]] = str_replace('"', '', $match[2]);
        }

        $command = array('command' => $command, 'arg1' => implode(' ', $unnamed_args), 'uargs' => $unnamed_args, 'raw' => implode(' ', $unnamed_args));

        return array_merge($command, $named_args);
    }
}
