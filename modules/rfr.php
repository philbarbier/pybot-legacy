<?php

    /* Display what's playing on RFR */
    public function rfr($args = null) {
        $str = file_get_contents('https://riboflav.in/rfr/api/ices_current');
        $this->write("PRIVMSG", $this->get_current_channel(), $str);
    }
    public function skip($args) {
	    return;
        $str = file_get_contents('http://riboflav.in/rfr/api/skip');
        sleep(2);
        $this->rfr();
    }


