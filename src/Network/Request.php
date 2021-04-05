<?php

declare(strict_types = 1);

namespace ResumableJs\Network;

interface Request
{

    /**
     * @param $type get/post
     * @return bool
     */
    public function is($type);

    /**
     * @param $requestType GET/POST
     * @return mixed
     */
    public function data($requestType);

    /**
     * @return FILES data
     */
    public function file();

}
