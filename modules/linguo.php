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
        $ircClass = $this->config['_ircClassName'];
        $ircClass::setCallList(__CLASS__, $this->config['_callee']);
        echo __CLASS__ . " construct\n";
        $this->abuse_requester = false;
        try {
            $this->connection = new Mongo($this->config['mongodb']);
        } catch (Exception $e) {
            sleep(1);
            $this->connection = new Mongo($this->config['mongodb']);
        }
        $this->collection = $this->connection->pybot;
    }

    public function __destruct()
    {
        echo "destructing class " . __CLASS__ . "\n";
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

    public function get_rant($params = array())
    {
        $in_tpl = (isset($params['tpl'])) ? $params['tpl'] : false;
        $in_who = (isset($params['arg1'])) ? $params['arg1'] : false;
        $template = $this->_get_template($in_tpl);
        if ($in_tpl === false) {
            while (strpos($template, '$who') !== false) { // dont use a tpl with who, unless we specified it
                $template = $this->_get_template($in_tpl);
            }
        }

        return $this->_generate_phrase($template, $in_who, @$params['sw']); // @TODO implement the old "$l" from above once parser is implemented
    }

    public function testtpl($params = array())
    {
        return trim($this->_generate_phrase($params['arg1'], null, 0));
    }

    private function _get_template($id = 0)
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
            }
        }
        
        $this->setLastTpl($tpl_id, $tpl_user);

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

    private function _generate_phrase($template_string, $who, $letter = null)
    {
        $words = explode(' ', $template_string);
        $phrase = '';
        $pos = 1;
        // $abusedata = array();
        // $abusedata['tpl_id'] = $tpl_id;
        foreach ($words as $word) {
            $prefix = Strings::prefix('$', $word);

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

                switch ($command) {
                    case 'ip':
                        $wd = rand(1, 254).'.'.rand(1, 254).'.'.rand(1, 254).'.'.rand(1, 254);
                        $suffix = Strings::suffix('$ip', $word);
                    break;
                    case 'who':
                        $wd = $who;
                        $suffix = Strings::suffix('$who', $word);
                    break;
                    case 'tomorrow':
                        $wd = date('l', strtotime('tomorrow'));
                        $suffix = Strings::suffix('$tomorrow', $word);
                    break;
                    case 'yesterday':
                        $wd = date('l', strtotime('yesterday'));
                        $suffix = Strings::suffix('$yesterday', $word);
                    break;
                    case 'date':
                        $wd = date('l F j, Y');
                        $suffix = Strings::suffix('$today', $word);
                    break;
                    case 'today':
                        $wd = date('l');
                        $suffix = Strings::suffix('$today', $word);
                    break;
                    case 'rand':
                        $wd = rand(18, 99);
                        $suffix = Strings::suffix('$rand', $word);
                    break;
                    case 'dice':
                        $wd = rand(1, 12);
                        $suffix = Strings::suffix('$rand', $word);
                    break;
                    case 'highrand':
                        $wd = rand(10000000, 99999999);
                        $suffix = Strings::suffix('$highrand', $word);
                    break;
                    case 'cc':
                        $suffix = Strings::suffix('$cc', $word);
                        $wd = Actions::getcc();
                    break;
                    default:
                        foreach ($this->_get_word_types() as $type) {
                            if (strstr($word, '$'.$type)) {
                                $wordtype = $type;
                                break;
                            }
                        }
                        $suffix = Strings::suffix('$'.$wordtype, $word);

                        if ($letter) {
                            $worddata = $this->_get_subword_word($wordtype, $letter);
                        } else {
                            $wordtype = $type;
                            $worddata = $this->_get_word($wordtype);
                        }
                        if (isset($worddata['word'])) {
                            $wd = $worddata['word'];
                            // @TODO when we implement the IDs, add this back 
                            $wid = false; // $worddata['id'];
                        }
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
        return implode(', ', $this->_get_word_types());
    }

    public function get_random_word_type()
    {
        $types = $this->_get_word_types();

        return $types[rand(0, count($types))];
    }

    private function setLastTpl($tpl_id = false, $tpl_user = false)
    {
        if (!$tpl_id || !$tpl_user || !is_numeric($tpl_id)) return;

        $requester = $this->abuse_requester;

        $c = $this->collection->abuse->lasttpl;
        $criteria = array('user' => $requester);
        $data = array(
            'user' => $requester,
            'tpl_id' => $tpl_id,
            'tpl_user' => $tpl_user,
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
}
