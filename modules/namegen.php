<?php

/*
    Class NameGen:
        Generates names for fictitious individuals
        The names are sterotypical and ridiculous, and are solely intended for humor, laughter, and joy
*/

// require_once($_cwd . '/lib/strings.php');

class namegen
{
    public function generateName($args = null)
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

    public function writeName($args)
    {
        $this->write_channel($this->generateName($args));
    }
} // END - Class NameGen

