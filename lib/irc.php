<?php

class Irc 
{
    public function __construct($config)
    {
        $this->_classes = false;
        $this->config = $config;
        // lib/
        // $this->_loadModules($this->config['_cwd'] . '/lib');
        // modules/
        $this->_loadModules($this->config['_cwd'] . '/modules');
        $this->Log = new Log($config);
        $this->server = $config['irc_server'];
        $this->version = $config['version'];
        $this->port = $config['irc_port'];
        $this->channels = $config['irc_channels'];
        $this->handle = $config['irc_handle'];
        $this->first_connect = true;
        $this->set_socket($this->server, $this->port);
        $this->actions = new Actions($this->config);
        $this->_methods = $config['_methods'];
        $this->connect_complete = false;
        $this->retrieve_nick = false;
        $this->arb_counter = 0;
        $this->in_whois = false;
        $this->is_oper = false;
        $this->is_connected = false;
        $this->nick_change = null;
        $this->main();
    }

    public function __destruct()
    {
        echo 'destructing class ' . __CLASS__ . "\n";
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
                    break;
                default:
                    // reading is good
                    if (is_readable($_module_path . '/' . $file)) {
                        $pathinfo = pathinfo($_module_path . '/' . $file);

                        if (strtolower($pathinfo['extension']) == 'php') {
                            $expected_class = ucfirst(substr($file, 0, strpos($file, '.')));
                            $sample = file_get_contents($_module_path . '/' . $file, NULL, NULL, 0, 1024);

                            if ($this->_checkIfModuleLoaded($_module_path . '/' . $file)) {
                                continue;
                            }
                        
                            $res = false;
                            if (substr($sample, 0, 5) === '<?php') {
                                if (stristr($sample, 'class ' . $expected_class)) {
                                    $res = include $_module_path . '/' . $file;
                                    $newClass = array(
                                        'filename'  => $file, 
                                        'classname' => $expected_class,
                                        'md5sum'    => md5_file($_module_path . '/' . $file)
                                    );

                                    $this->config['_classes'][$expected_class] = $newClass; 
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

    private function _checkIfModuleLoaded($_module_path = '', $file = '')
    {
        // check if this file exists in config classes, if so, compare md5sum
        // call the destructor and reload the class
        return false;
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
            sleep(60);
            $this->__construct($this->config);
        }
    }

    private function destroy_socket()
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
        $this->write("NICK $nick");
        $this->actions->setBotHandle($nick);
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
        $this->write("USER $this->handle 8 *  :$this->handle");

        if (!$this->socket) {
            $this->Log->log('Socket error', 2);
            $this->destroy_socket();
            sleep(60);
            $this->__construct($this->config);
        }

        while (!feof($this->socket)) {
            $raw = $this->read();
            $this->actions->setsocket($this->socket);
            if (isset($raw) && !empty($raw)) {
                $msg = $this->parse_raw($raw);

                // Print message debug to stdout
                if ($this->config['debug']) {
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
                if ($a === 'reload') {
                    return 'destruct';
                }

                // $this->Log->log("Params: " . json_encode($params, true), 3);

                if (method_exists($this->actions, $a)) {
                    $result = $this->actions->$a($params);
                    $this->write($result);
                }

                /* THIS IS ANNOYING AS FUCK!
                if (!$this->actions->_check_acl($params)) {
                    // Log the offender ?
                    // $this->admin_message("Command denial: " . @implode(' ', $params));
                } else {
                    if (method_exists($this->actions, $a)) {
                        $result = $this->actions->$a($params);
                        $this->write($result);
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
        $this->write('WHOIS '.$nick);
    }

    private function get_arraykey($parts)
    {
        return md5(@$parts[3].@$parts[5]);
    }

    public function parse_raw($str)
    {
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

        // echo "\n" . date('Y-m-d H:i:s') . " -- RX: " . $str;

        if ($parts[0] == 'PING') {
            $this->write('PONG '.$parts[1]);
        }

        if ($parts[0] == 'ERROR') {
            $this->destroy_socket();
            sleep(120);
            $this->__construct($this->config);
        }

        $this->actions->set_parts($parts);

        $result = null;

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
        
        if (isset($parts[1]) && !empty($parts[1]) && (is_numeric($parts[1]) || $parts[1] == 'JOIN' || $parts[1] == 'PART' || $parts[1] == 'INVITE' || $parts[1] == 'KILL')) {
            // we should maybe parse for every code here, that way
            // we're able to tell the IRC state better
            switch (strtoupper($parts[1])) {
                // check WHOIS
                case 311:
                    $this->set_usercache($parts);
                    $this->in_whois = true;
                break;
                // checks WHOISOPERATOR
                case 313:
                    $this->is_oper = true;
                    $array_key = $this->actions->get_arraykey($parts);
                    if (isset($this->actions->userCache[$array_key]) && is_array($this->actions->userCache[$array_key])) {
                        $this->actions->userCache[$array_key]['isoper'] = 1;
                    }
                break;
                // checks end of WHOIS
                case 318:
                    $this->in_whois = false;
                break;
                // names list
                case 353:
                    foreach ($parts as $i => $val) {
                        if ($i > 4) {
                            $nick = Bleacher::ircnick($val);
                            if (!isset($this->actions->userCache[$nick])) {
                                $this->whois($nick);
                            }
                        }
                    }
                break;
                // end of names list
                case 366:
                    // build cache
                break;
                // change nick
                case 432:
                case 433:
                    $this->retrieve_nick = true;
                    if ($this->first_connect) {
                        $newnick = $this->get_newnick();
                        $this->set_nickname($newnick);
                    }
                    $this->actions->setBotHandle($parts[2]);
                    $this->nick_change = false;
                break;

                // we check here for MOTD/end of MOTD on join
                case 422:
                case 376:
                    //ready to join
                    $this->first_connect = false;
                    $this->connect_complete = true;
                break;
                // non-numerics

                // someone joined
                case 'JOIN':
                    $userinfo = $this->break_hostmask($parts[0]);
                    $this->whois($userinfo['nick']);
                break;
                // someone left the channel
                case 'PART':

                break;
                case "INVITE":
                    if (isset($parts[3])) {
                        $this->write('JOIN ' . $parts[3]);
                    }
                break;
                case "KILL":
                    $this->destroy_socket();
                    sleep(15);
                    $this->__construct($this->config);
                break;

            }
            if (!$this->in_whois) {
                $this->actions->set_isoper($this->is_oper);
                // $this->admin_message($this->actions->get_current_user() . " results: is_oper-local: " . $this->is_oper . " -- is_oper: " . $this->actions->get_isoper());
            }
        }

        if ($this->connect_complete) {
            foreach ($this->channels as $channel) {
                $this->write("JOIN $channel");
            }
            // ensure we're in the default channel
            if (!in_array($this->config['default_chan'], $this->channels)) {
                $this->write('JOIN '.$this->config['default_chan']);
            }
            // ensure we're in the admin channel
            if (!in_array($this->config['admin_chan'], $this->channels)) {
                $this->write('JOIN '.$this->config['admin_chan']);
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
                if (!isset($this->actions->userCache[$array_key])) {
                    $this->actions->userCache[$array_key] = array();
                }

                $this->actions->userCache[$array_key]['userhash'] = md5(@$parts[3].@$parts[5]);
                $this->actions->userCache[$array_key]['isoper'] = 0;
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
        return $this->actions->userCache;
    }

    public function admin_message($message = '')
    {
        if (empty($message)) {
            return false;
        }
        $this->write('PRIVMSG '.$this->config['admin_chan'].' :'.$message);
    }

    // re-read config file
    private function _rehash()
    {
        if (isset($config_path) && file_exists($config_path) && is_readable($config_path)) {
            include $config_path;
        }
    }

    public function break_hostmask($hm = '')
    {
        if (empty($hm) || !is_string($hm)) {
            return false;
        }

        $data = array();

        list($first, $second) = explode('@', $hm);
        list($dirtynick, $ident) = explode('!', $first);

        $data['nick'] = Bleacher::ircnick($dirtynick);
        $data['ident'] = $ident;
        $data['host'] = $second;

        return $data;
    }

    public function write($message)
    {
        /*
        echo "\n" . date('Y-m-d H:i:s') . ' -- TX: ';
        echo $message;
        */
        if (!$this->socket) {
            $this->Log->log('Socket error', 2);
            $this->destroy_socket();
            sleep(60);
            $this->__construct($this->config);

            return false;
        }
        
        try {
            $res = fwrite($this->socket, $message."\r\n\r\n", strlen($message."\r\n\r\n"));
        } catch (Exception $e) {
            $res = false;
            $this->Log->log("Couldn't write to the socket", 3);
            $this->Log->log($e->getMessage());
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
