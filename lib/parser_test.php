<?php

$teststr1 = "abuse person --tpl 421";
$teststr2 = "command word1 word2 word3 word4";
#$teststr2 = 'jump "really high" --reason "no reason" --because because';
$teststr3 = "mktpl one big long sentence sans quotes";
$teststr4 = 'command arg1 arg2 arg3 --namedarg1 noquotes arg4';
$teststr5 = 'command arg1 "arg 2" --namedarg1 "w quotes" arg3 --namedarg2 noquotes arg4';

function parse_command($str) {
        // get command name
        $matches = array();
        $matched =  preg_match('/^\S+/',$str,&$matches);
        $command = $matches[0];

        // get raw and unnamed args
        $matched = preg_match_all('/\s+((\"[^\"]*\")|(\S+))/',$str,&$matches,PREG_SET_ORDER);
        
        $unnamed_args = array();
        $found_unnamed = false;
        foreach ($matches as $match) {
            if (preg_match('/^--/',$match[1]) || $found_unnamed) {
               $found_unnamed = !$found_unnamed;
               continue;
            }
            array_push($unnamed_args,str_replace('"',"",$match[1]));
        }   
        $arg1 = str_replace('"','',$matches[3]);
        // get named args
        $matches = array();
        $matched = preg_match_all('/--(\S+)\s+((\"[^\"]*\")|([^\"]\S*))/',$str,&$matches,PREG_SET_ORDER);
        $named_args = array();

        foreach ($matches as $match) {
            $named_args[$match[1]] = str_replace('"','',$match[2]);
        }

        $command = array('command'=>$command,'arg1'=>$unnamed_args[0],'uargs'=>$unnamed_args,'raw'=>join(' ',$unnamed_args));
        return array_merge($command,$named_args);
    }

    print_r(parse_command($teststr1));
    print_r("####################");
    print_r(parse_command($teststr2));
    print_r("####################");
    print_r(parse_command($teststr3));
    print_r("####################");
    print_r(parse_command($teststr4));
    print_r("###################");
    print_r(parse_command($teststr5));

?>
