<?php
class Linguo
{

/*
    Class Linguo:
        Intended to handle all language related functions
        such as selecting a word (given optional criteria),
        generating a phrase, analysing a sentence, logging diction usage for NLTK?

*/

    public function __construct($options = array())
    {
        // do some stuff
        $this->config = $options;
        if (array_key_exists(__CLASS__, $this->config['_classes'])) {
            $ircClass = $this->config['_ircClassName'];
            $ircClass::setCallList(__CLASS__, $this->config['_callee']);
        }
        $this->abuse_requester = false;
        try {
            $this->connection = new Mongo($this->config['mongodb']);
        } catch (Exception $e) {
            sleep(1);
            $this->connection = new Mongo($this->config['mongodb']);
        }
        $this->collection = $this->connection->pybot;
        
        $class = $this->config['_classes']['Strings']['classname']; 
        $this->strings = new $class($this->config);
    }

    public function __destruct()
    {
    }

    public function get_abuse($params = array())
    {
        $in_tpl = (isset($params['tpl'])) ? $params['tpl'] : false;
        $in_who = (isset($params['arg1'])) ? $params['arg1'] : false;
        $template = $this->_get_template($in_tpl);
        if ($in_who !== false && $in_tpl === false) { // if we do have a who, but haven't specified a tpl...
            while (strpos($template, '$who') === false) {
                $template = $this->_get_template($in_tpl);
            }
        } elseif ($in_tpl === false) { // if we DON'T have a who, and haven't specified a tpl, get a rant
            return $this->get_rant($params);
        } else { // if we don't have a who and have specified a tpl, whatever, do what the fuck you want
            // oh we already picked it out nvm  
        }

        return $this->_generate_phrase($template, $in_who, @$params['sw']); // @TODO implement the old "$l" from above once parser is implemented
    }

    public function get_rant($params = array(), $dontlog = false)
    {
        $in_tpl = (isset($params['tpl'])) ? $params['tpl'] : false;
        $in_who = (isset($params['arg1'])) ? $params['arg1'] : false;
        $template = $this->_get_template($in_tpl, $dontlog);
        if ($in_tpl === false) {
            while (strpos($template, '$who') !== false) { // dont use a tpl with who, unless we specified it
                $template = $this->_get_template($in_tpl, $dontlog);
            }
        }

        return $this->_generate_phrase($template, $in_who, @$params['sw']); // @TODO implement the old "$l" from above once parser is implemented
    }

    public function testtpl($params = array())
    {
        return trim($this->_generate_phrase($params['arg1'], null, 0));
    }

    public function getTemplateString($id = false)
    {
        if (!$id) return;
        $template_string = '';
        $tpl_id = $id;
        $tpl_user = false;
        if ($id > 0) {
            $criteria = array('id' => (int) $id);
            $template = $this->collection->templates->findOne($criteria);
            if ($template) {
                $template_string = $template['template'];
                $tpl_user = $template['user'];
                $timestamp = $template['time'];
            }
        }
        if (empty($template_string)) {
            return "Couldn't find template string";
        }
        return $template_string . ' - ' . $tpl_user . ' (' . date($this->config['_dateFormat'], $timestamp) . ')';
    }

    private function _get_template($id = 0, $dontlog = false)
    {
        $template_string = '';
        $tpl_id = $id;
        $tpl_user = false;
        if ($id > 0) {
            $criteria = array('id' => (int) $id);
            $template = $this->collection->templates->findOne($criteria);
            if ($template) {
                $template_string = $template['template'];
                $tpl_user = $template['user'];
                $timestamp = $template['time'];
            }
        }
        // we do this here in case the ID supplied doesn't yield a result
        if (strlen($template_string) == 0) {
            $count = $this->collection->templates->count();
            $rand = rand(0, $count - 1);
            $result = $this->collection->templates->find()->skip($rand)->limit(1);
            foreach ($result as $data) {
                $template_string = $data['template'];
                $tpl_id = $data['id'];
                $tpl_user = $data['user'];
                $timestamp = $data['time'];
            }
        }
       
        $tpldata = array(
            'timestamp' => $timestamp,
            'tpl_id' => $tpl_id,
            'tpl_user' => $tpl_user);

        if ($dontlog === false) {
            $this->setLastTpl($tpldata);
        }

        return $template_string;
    }

    private function _get_word($type)
    {
        $criteria = array('type' => $type);
        // Determine how many words in set.
        $count = $this->collection->words->count($criteria);
        // This is how much we'll skip by (a mongo Random document hack).
        $rand = rand(0, $count - 1);
        // Choose a random word that matches type
        $data = $this->collection->words->find($criteria)->skip($rand)->limit(1);
        // Convert cursor to array.
        $result = current(iterator_to_array($data));

        return @$result;
    }

    public function get_word($type = '')
    {
        return $this->_get_word($type);
    }

    public function get_word_that_starts_with($letter = 'a')
    {
        return $this->_get_word_that_starts_with($letter);
    }

    private function _get_subword_word($type, $subword)
    {
        $criteria = array('type' => $type);
        $count = $this->collection->words->count($criteria);
        $rand = rand(0, $count - 1);
        $criteria['word'] = new MongoRegex('/^'.$subword.'/i');
        $data = $this->collection->words->find($criteria)->skip($rand)->limit(1);
        $result = current(iterator_to_array($data));

        return @$result;
    }

    public function get_subword_word($type, $subword)
    {
        return $this->_get_subword_word($type, $subword);
    }

    private function _get_word_types()
    {
        $data = $this->collection->command(array('distinct' => 'words', 'key' => 'type'));
        $types = array();

        foreach ($data['values'] as $key => $type) {
            if (!empty($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function _getWordStrings($w = false, $word = false, $command = false, $who = false)
    {
        switch ($command) {
            case 'ip':
                $wd = rand(1, 254).'.'.rand(1, 254).'.'.rand(1, 254).'.'.rand(1, 254);
                $suffix = $this->strings->suffix('$ip', $word);
            break;
            case 'who':
                $wd = $who;
                $suffix = $this->strings->suffix('$who', $word);
            break;
            case 'tomorrow':
                $wd = date('l', strtotime('tomorrow'));
                $suffix = $this->strings->suffix('$tomorrow', $word);
            break;
            case 'yesterday':
                $wd = date('l', strtotime('yesterday'));
                $suffix = $this->strings->suffix('$yesterday', $word);
            break;
            case 'date':
                $wd = date('l F j, Y');
                $suffix = $this->strings->suffix('$today', $word);
            break;
            case 'today':
                $wd = date('l');
                $suffix = $this->strings->suffix('$today', $word);
            break;
            case 'rand':
                $wd = rand(18, 99);
                $suffix = $this->strings->suffix('$rand', $word);
            break;
            case 'dice':
                $wd = rand(1, 12);
                $suffix = $this->strings->suffix('$rand', $word);
            break;
            case 'highrand':
                $wd = rand(10000000, 99999999);
                $suffix = $this->strings->suffix('$highrand', $word);
            break;
            case 'cc':
                $suffix = $this->strings->suffix('$cc', $word);
                $wd = Actions::getcc();
            break;
            default:
                $ret = $this->_findWords($w, $word);
                $prefix = $ret['prefix'];
                $wd     = $ret['wd'];
                $suffix = $ret['suffix'];
        }
        
        $return = array();
        if (isset($wd))     $return['wd']       = $wd;
        if (isset($suffix)) $return['suffix']   = $suffix;
        if (isset($prefix)) $return['prefix']   = $prefix;
       
        return $return;    
    }

    private function _findWords($w = '', $word = '')
    {
        $prefix = $wd = $suffix = false;
        $types = $this->_get_word_types();

        $w = preg_replace('/[^a-zA-Z\$]/', '', $w);

        $as = array_search(strtolower($w), $types);
       
        $wordtype = false;
        if ($as) {
            $wordtype = $type = $types[$as];
        } else {
            foreach ($types as $type) {

                // @TODO for $dietys sake, fix this some time

                $adjlow = 1;
                $adjsl = 6;
                $adjfl = 4;
                $adjnew = 1;

                $adjuster = floor(strlen($word) / $adjfl);
                if (($adjuster <= $adjlow) && (strlen($word) >= $adjsl)) $adjuster = $adjnew;
                if (strlen($word) >= $adjsl) $adjuster++;
                $thing =  substr($word, 0, (strlen($word)-($adjuster)));
                $thing = preg_replace('/[^a-zA-Z\$]/', '', $thing);
                if (strlen($thing) <= 2) $thing = preg_replace('/[^a-zA-Z\$]/', '', $word);
                if (stristr('$' . $type, $thing)) { 
                    $wordtype = $type;
                    break;
                }
            }
            if (!$wordtype) {
                foreach ($types as $type) {
                    if (stristr($word, '$' . $type)) { 
                        $wordtype = $type;
                        break;
                    }
                }
                
            }
        }

        $suffix = $this->strings->suffix('$' . $wordtype, $word);
        /*
        var_dump($wordtype);
        var_dump($word);
        var_dump($suffix);
        */
        if (strpos($word, $wordtype) > 1) {
            $prefix = $this->strings->prefix('$' . $wordtype, $word);
        }

        $worddata = $this->_get_word($wordtype);
        
        if (isset($worddata['word'])) {
            $wd = $worddata['word'];
            // @TODO when we implement the IDs, add this back 
            $wid = false; // $worddata['id'];
        }

        return array(
            'prefix'    => $prefix,
            'wd'        => $wd,
            'suffix'    => $suffix
        );
    }

    private function _generate_phrase($template_string, $who, $letter = null)
    {
        $words = explode(' ', $template_string);
        $phrase = '';
        $pos = 1;
        // $abusedata = array();
        // $abusedata['tpl_id'] = $tpl_id;
        foreach ($words as $word) {
            $prefix = $this->strings->prefix('$', $word);

            # $: a candidate for removal
            $w = str_replace('$', '', $word);
            $command = '';
            $wd = '';
            $suffix = '';
            $wordtype = '';
            $wid = 0;
            # is this a command
            if (strstr($word, '$')) {
                # yeah totally dude
                if (strstr($word, '$tomorrow')) {
                    $command = 'tomorrow';
                }
                if (strstr($word, '$yesterday')) {
                    $command = 'yesterday';
                }
                if (strstr($word, '$date')) {
                    $command = 'date';
                }
                if (strstr($word, '$ip')) {
                    $command = 'ip';
                }
                if (strstr($word, '$today')) {
                    $command = 'today';
                }
                if (strstr($word, '$highrand')) {
                    $command = 'highrand';
                }
                if (strstr($word, '$who')) {
                    $command = 'who';
                } elseif (strstr($word, '$rand')) {
                    $command = 'rand';
                } elseif (strstr($word, '$dice')) {
                    $command = 'dice';
                } elseif (strstr($word, '$cc')) {
                    $command = 'cc';
                }
                $stuff = $this->_getWordStrings($w, $word, $command, $who);
                $limiter = 0;
                while (!isset($stuff['wd'])) {
                    $stuff = $this->_getWordStrings($w, $word, $command, $who);
                    if ($limiter > 10) break;
                    $limiter++;
                }

                $wd = isset($stuff['wd']) ? $stuff['wd'] : false;
                if (isset($stuff['suffix'])) $suffix = $stuff['suffix'];
                if (isset($stuff['prefix'])) $prefix = $stuff['prefix'];
                
                //print_r($stuff);

                $pattern = '/[^a-zA-Z\$]/';
                if (strstr($suffix, '$')) {
                    preg_match($pattern, $suffix, $sfxmatch);
                    $str = preg_replace($pattern, '', substr($suffix, strpos($suffix, '$')+1));
                    $subworddata = $this->_get_word($str);
                    if (isset($subworddata['word'])) {
                        if (isset($sfxmatch[0])) {
                            if (strpos($suffix, '$') == 0) {
                                $subworddata['word'] .= $sfxmatch[0];
                            } else {
                                $subworddata['word'] = $sfxmatch[0] . $subworddata['word']; 
                            }
                        }
                    } else {
                        // could be a command, try that
                        $stuff = $this->_getWordStrings($w, $word, $str, $who);
                        
                        if (isset($sfxmatch[0])) {
                            if (strpos($suffix, '$') == 0) {
                                $suffix .= $sfxmatch[0];
                            } else {
                                $suffix = $sfxmatch[0];
                            }
                        }

                        if (isset($stuff['wd'])) $suffix .= $stuff['wd'];
                        
                    }
                }

                if (strstr($prefix, '$')) {
                    preg_match($pattern, $prefix, $pfxmatch);
                    $str = preg_replace($pattern, '', substr($prefix, 1));
                    $pfxworddata = $this->_get_word($str);
                    if (isset($pfxworddata['word'])) {
                        if (isset($pfxmatch[0])) {
                            if (strpos($prefix, '$') == 0) {
                                $pfxworddata['word'] .= $pfxmatch[0];
                            } else {
                                $pfxworddata['word'] = $pfxmatch[0] . $pfxworddata['word'];
                            }
                        }
                    } else {
                        $stuff = $this->_getWordStrings($w, $word, $str, $who);
                        if (isset($pfxmatch[0])) {
                            if (strpos($prefix, '$') == 0) {
                                $prefix .= $pfxmatch[0];
                            } else {
                                $prefix = $pfxmatch[0];
                            }
                        }

                        if (isset($stuff['wd'])) $prefix = $prefix . $stuff['wd'];
                    }
                }

                if (isset($subworddata['word'])) {
                    $suffix = $subworddata['word'];
                }
                if (isset($pfxworddata['word'])) {
                    $prefix = $pfxworddata['word'];
                }

                $phrase .= $prefix.$wd.$suffix.' ';
            } else {
                $wd = $word;
                $phrase .= $word.' ';
            }
            if ($wid > 0) {
                // Here's where we store any abuse data (for word/use stats)
                //$abusedata[] = array('wordid'=>$wid, 'word'=>$wd);
            }
            ++$pos;
        }

        return stripslashes($phrase);
    }

    /* ###### IRC Functions ###### */
    public function get_word_types()
    {
        $types = $this->_get_word_types();
        // for The_Hatta <3
        sort($types);
        return implode(', ', $types);
    }

    public function get_random_word_type()
    {
        $types = $this->_get_word_types();

        return $types[rand(0, count($types))];
    }

    private function setLastTpl($tpl_data = array()) 
    {
        if (!isset($tpl_data['tpl_id']) || !isset($tpl_data['tpl_user']) || !is_numeric($tpl_data['tpl_id'])) return;

        $requester = $this->abuse_requester;

        $c = $this->collection->abuse->lasttpl;
        $criteria = array('user' => $requester);
        $data = array(
            'user' => $requester,
            'tpl_id' => $tpl_data['tpl_id'],
            'tpl_user' => $tpl_data['tpl_user'],
            'tpl_time' => $tpl_data['timestamp'],
            'timestamp' => date('U')
        );
        
        $c->update($criteria, $data, array('upsert' => true));
    }

    public function getLastTpl($args = false) 
    {
        $user = !$args ? false : $args['arg1'];
        $c = $this->collection->abuse->lasttpl;
        $criteria = false; 
        if ($user) {
            $criteria = array('user' => $user);
        }
        

        if ($criteria) {
            $data = $c->findOne($criteria);
        } else {
            $result = $c->find()->sort(array('timestamp' => -1))->limit(1);
            foreach($result as $row) {
                $data = $row;
            }
        }
        
        $return = array();

        if (isset($data['tpl_id'])) {
            $return = $data;
            if (!$user) {
                $return['no_user'] = true;
            } else {
                $return['user'] = $user;
            }
        }
        return $return;
    }

    /*
        sets the last user who requested an abuse
        so we can log this isht
    */
    public function setLastRequester($handle = false)
    {
        if (!$handle) return false;
        $this->abuse_requester = $handle;
    }

    public function getUserCacheDB()
    {
        return iterator_to_array($this->collection->irc->usercache->find());
    }

}
