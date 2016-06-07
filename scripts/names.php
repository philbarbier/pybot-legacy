<?php

$con = new MongoClient();
$col = $con->pybot->words;
$list = explode("\n", file_get_contents('http://deron.meranda.us/data/census-dist-male-first.txt'));
foreach ($list as $line) {
    $name = ucfirst(strtolower(current(explode(' ', $line))));
    $name = trim(ucfirst(strtolower(current(explode(' ', $line)))));
    if (!$name) {
        continue;
    }
    $criteria = array(
        'user' => 'pybot',
        'word' => $name,
    );
    $data = array(
        'type' => 'firstname',
        'user' => 'pybot',
        'word' => $name,
    );
    $col->update($criteria, $data, array('upsert' => true));
    print_r($data);
}

$list = explode("\n", file_get_contents('http://deron.meranda.us/data/census-dist-female-first.txt'));
foreach ($list as $line) {
    $name = trim(ucfirst(strtolower(current(explode(' ', $line)))));
    if (!$name) {
        continue;
    }
    $criteria = array(
        'user' => 'pybot',
        'word' => $name,
    );
    $data = array(
        'type' => 'firstname',
        'user' => 'pybot',
        'word' => $name,
    );
    $col->update($criteria, $data);
    print_r($data);
}

$list = explode("\n", file_get_contents('http://deron.meranda.us/data/census-dist-2500-last.txt'));
foreach ($list as $line) {
    $name = trim(ucfirst(strtolower(current(explode(' ', $line)))));
    if (!$name) {
        continue;
    }
    $criteria = array(
        'user' => 'pybot',
        'word' => $name,
    );
    $data = array(
        'type' => 'lastname',
        'user' => 'pybot',
        'word' => $name,
    );
    $col->update($criteria, $data);
    print_r($data);
}

$list = explode("\n", file_get_contents('http://deron.meranda.us/data/popular-both-first.txt'));
foreach ($list as $line) {
    $name = trim(ucfirst(strtolower(current(explode(' ', $line)))));
    if (!$name) {
        continue;
    }
    $criteria = array(
        'user' => 'pybot',
        'word' => $name,
    );
    $data = array(
        'type' => 'firstname',
        'user' => 'pybot',
        'word' => $name,
    );
    $col->update($criteria, $data);
    print_r($data);
}
