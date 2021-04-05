<?php

declare(strict_types = 1);

namespace ResumableJs\Network;

class SimpleRequest implements Request
{

    /**
     * @param $type get/post
     * @return bool
     */
    public function is($type)
    {
        switch (strtolower($type)) {
            case 'post':
                return isset($_POST) && !empty($_POST);
            case 'get':
                return isset($_GET) && !empty($_GET);
        }
        return false;
    }

    /**
     * @param $requestType GET/POST
     * @return mixed
     */
    public function data($requestType)
    {
        switch (strtolower($requestType)) {
            case 'post':
                return isset($_POST) ? $_POST : [];
            case 'get':
                return isset($_GET) ? $_GET : [];
        }
        return [];
    }

    /**
     * @return FILES data
     */
    public function file()
    {
        if (!isset($_FILES) || empty($_FILES)) {
            return [];
        }
        $files = array_values($_FILES);
        return array_shift($files);
    }

}
