<?php

$user = get_current_user();
$config_path = "/home/$user/.pybotrc";
if (!file_exists($config_path)) {
    die("FATAL: Could not find configuration in $config_path\n");
}
if (!is_readable($config_path)) {
    die("FATAL: Configuration file $config_path cannot be read or I don't have permission\n");
}

if (!isset($_cwd)) {
    // this means we're not being called by the bot
    $pi = pathinfo(__FILE__);
    $_cwd = $pi['dirname'].'/..';
}

include $config_path;
$config['version'] = '4.0.'.file_get_contents($_cwd.'/VERSION');

require '../modules/linguo.php';

$c = new pybot($config);

class pybot
{
    public function __construct($config)
    {
        $this->linguo = new Linguo($config);
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            if ($_GET['a'] == 'abuse') {
                $params = array();
                if (isset($_GET['who'])) {
                    $params['arg1'] = $_GET['who'];
                }
                if (isset($_GET['tpl'])) {
                    $params['tpl'] = $_GET['tpl'];
                }
                echo $_REQUEST['callback'].'('.json_encode(array('response' => $this->linguo->get_abuse($params))).')';
                exit;
            } else {
                echo $_REQUEST['callback'].'('.json_encode(array('error' => 1)).')';
                exit;
            }
        } else {
            echo $_REQUEST['callback'].'('.json_encode(array('error' => 1)).')';
            exit;
        }
    }

    public function __destruct()
    {
    }
}
