<?php

class Log
{
    public function __construct($config)
    {
        $this->config = $config;
        $this->logfile = $this->config['logfile'];

        if (!isset($this->config['_origclass'])) {
            $this->config['_origclass'] = __CLASS__;
        }
        if (isset($this->config['_callee'])) {
            $this->config['_callee'][] = $this->config['_origclass'];
        }
        if (array_key_exists(__CLASS__, $this->config['_classes'])) {
            $ircClass = $this->config['_ircClassName'];
            $ircClass::setCallList(__CLASS__, $this->config['_callee']);
        }

        $class = $this->config['_classes']['Colours']['classname'];
        $this->colours = new $class($this->config);


    }

    public function __destruct()
    {

    }

    public function log($action, $severity = 0)
    {
        switch ($severity) {
            case 0 :
                $this->severity = 'ALL';
                $this->color = 'green';
                break;
            case 1 :
                $this->severity = 'INFO';
                $this->color = 'blue';
                break;
            case 2 :
                $this->severity = 'WARNING';
                $this->color = 'red';
                break;
            case 3 :
                $this->severity = 'DEBUG';
                $this->color = 'white';
                break;
        }
        $date = date('Y/m/d g:i');
        $message = $this->colours->getColoredString("[$date] $this->severity > $action", $this->color).PHP_EOL;
        file_put_contents($this->logfile, $message, FILE_APPEND);
    }
}


