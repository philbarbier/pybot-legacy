<?php

class bleacher
{
    private static $_patterns = array(
        'int' => "/[^0-9\-]+/",
        'float' => "/[^0-9\.\,\-]+/",
        'email' => "/[^a-zA-Z0-9\@\.\-\_\+]+/",
        'text' => "/[^a-zA-Z0-9\-\_\.\,\(\)\[\]\?\!\/ ]+/",
        'message' => "/[^a-zA-Z0-9\-\_\.\,\(\)\[\]\?\!\/<>: =]+/",
        'string' => "/[^a-zA-Z0-9\-\_\.\,\+\!\?]+/",
        'alphabetic' => '/[^a-zA-Z]+/',
        'ircnick' => "/[^a-zA-Z0-9\-\_]+/",
        'uppercase' => '/[^A-Z]+/',
        'lowercase' => '/[^a-z]+/',
        'mysqldate' => "/[^\d{4}\/\d{2}\/\d{2}]+/",
        'loose' => "/[^a-zA-Z0-9\-\_]+/",
        'strict' => '/[^a-zA-Z0-9]+/',
        'password' => "/[^a-zA-Z0-9\?\.\_\!\$\#]+/",
        'numeric' => '/[^0-9]+/',
        'url' => "/[^a-zA-Z0-9\/\#\?\&\-\_\=\:\.]+/",
    );

    final public static function int($i = 0)
    {
        return (int) preg_replace(self::$_patterns['int'], '', $i);
    }

    final public static function float($f = 0.00)
    {
        return (float) preg_replace(self::$_patterns['float'], '', $f);
    }

    final public static function email($email = '')
    {
        return (string) preg_replace(self::$_patterns['email'], '', $email);
    }

    final public static function text($str = '')
    {
        return (string) preg_replace(self::$_patterns['text'], '', $str);
    }

    final public static function message($msg = null)
    {
        return (string) preg_replace(self::$_patterns['message'], '', $msg);
    }

    final public static function string($str = '')
    {
        return (string) preg_replace(self::$_patterns['string'], '', $str);
    }

    final public static function alphabetic($str = '')
    {
        return (string) preg_replace(self::$_patterns['alphabetic'], '', $str);
    }

    final public static function ircnick($str = '')
    {
        return (string) preg_replace(self::$_patterns['ircnick'], '', $str);
    }

    final public static function uppercase($str = '')
    {
        return (string) preg_replace(self::$_patterns['uppercase'], '', $str);
    }

    final public static function lowercase($str = '')
    {
        return (string) preg_replace(self::$_patterns['lowercase'], '', $str);
    }

    final public static function mysqldate($str = '')
    {
        return (string) preg_replace(self::$_patterns['mysqldate'], '', $str);
    }

    final public static function loose($str = '')
    {
        return (string) preg_replace(self::$_patterns['loose'], '', $str);
    }

    final public static function strict($str = '')
    {
        return (string) preg_replace(self::$_patterns['strict'], '', $str);
    }

    final public static function password($str = '')
    {
        return (string) preg_replace(self::$_patterns['password'], '', $str);
    }

    final public static function numeric($num = 0)
    {
        return (int) preg_replace(self::$_patterns['numeric'], '', $num);
    }

    final public static function url($str = '')
    {
        return (string) preg_replace(self::$_patterns['url'], '', $str);
    }
}
