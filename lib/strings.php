<?php
class Strings
{
    public static function start()
    {
        return 'start';
    }

    public static function prefix($substr, $str)
    {
        return substr($str, 0, strpos($str, $substr));
    }

    public static function suffix($substr, $str)
    {
        $i = strrpos($str, $substr);

        return substr($str, $i + strlen($substr), strlen($str));
    }

    public static function array_search_recursive($needle, $haystack)
    {
        $path = array();
        foreach ($haystack as $id => $val) {
            if ($val === $needle) {
                $path[] = $id;
                break;
            } elseif (is_array($val)) {
                $found = self::array_search_recursive($needle, $val);
                if (count($found) > 0) {
                    $path[$id] = $found;
                    break;
                }
            }
        }

        return $path;
    }
}
