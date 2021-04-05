<?php
namespace ResumableJs\Network;

interface Response {

    /**
     * @param $statusCode
     * @return mixed
     */
    public function header($statusCode);

}