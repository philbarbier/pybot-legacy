<?php

class Twitter
{
    public function __construct($config = array()) 
    {
        $this->config = $config;
        if (array_key_exists(__CLASS__, $this->config['_classes'])) {
            $ircClass = $this->config['_ircClassName'];
            $ircClass::setCallList(__CLASS__, $this->config['_callee']);
        }
    }

    public function __destruct() 
    {
    }

    public function upload($path)
    {
        $client = new tmhOAuth(array(
          'consumer_key' => 'kkqx07OVap3VClYjQm6vcA',
          'consumer_secret' => '14eObQCm0EsAh2dTjwlG6fJ5osVsyYmiB8rIfV0M',
          'user_token' => '2176663651-bVALnSABGhQgfZpR2KgCtwL8cEXpYkRfJRPvEwy',
          'user_secret' => 'Groygdk79INg4NonprSMjYZvshGh2mUgYhKvAHRqi97c0',
          'curl_ssl_verifypeer' => false,
        ));

        if (class_exists('CurlFile', false)) {
            $media = new CurlFile($path);
        } else {
            $media = "@{$path};type=image/jpeg;filename=lol.jpg";
        }

        $options = array(
            'method' => 'POST',
            #'url'    => $client->url("1.1/media/upload.json"),
            'url' => 'https://upload.twitter.com/1.1/media/upload.json',
            'params' => array(
                'media' => $media,
            ),
            'multipart' => true,
        );

        $code = $client->user_request($options);

        return $code;
    }

    public function tweet($message)
    {
        $tmhOAuth = new tmhOAuth(array(
          'consumer_key' => 'kkqx07OVap3VClYjQm6vcA',
          'consumer_secret' => '14eObQCm0EsAh2dTjwlG6fJ5osVsyYmiB8rIfV0M',
          'user_token' => '2176663651-bVALnSABGhQgfZpR2KgCtwL8cEXpYkRfJRPvEwy',
          'user_secret' => 'Groygdk79INg4NonprSMjYZvshGh2mUgYhKvAHRqi97c0',
          'curl_ssl_verifypeer' => false,
        ));

        $options = array(
            'method' => 'POST',
            'url' => $tmhOAuth->url('1.1/statuses/update'),
            'params' => array(
                'status' => $message,
            ), true, true,
        );

        $code = $tmhOAuth->user_request($options);

        return $code;
    }

    public function follow($message)
    {
        $tmhOAuth = new tmhOAuth(array(
          'consumer_key' => 'kkqx07OVap3VClYjQm6vcA',
          'consumer_secret' => '14eObQCm0EsAh2dTjwlG6fJ5osVsyYmiB8rIfV0M',
          'user_token' => '2176663651-bVALnSABGhQgfZpR2KgCtwL8cEXpYkRfJRPvEwy',
          'user_secret' => 'Groygdk79INg4NonprSMjYZvshGh2mUgYhKvAHRqi97c0',
        ));

        $options = array(
            'method' => 'POST',
            'url' => $tmhOAuth->url('1.1/friendships/create'),
            'params' => array(
                'screen_name' => $message,
            ),
        );

        $code = $tmhOAuth->user_request($options);

        return $code;
    }
}
