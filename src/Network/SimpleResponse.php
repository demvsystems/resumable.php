<?php

declare(strict_types = 1);

namespace ResumableJs\Network;

class SimpleResponse implements Response
{

    /**
     * @param $statusCode
     * @return mixed
     */
    public function header($statusCode)
    {
        if (200 == $statusCode) {
            return header('HTTP/1.0 200 Ok');
        } elseif (404 == $statusCode) {
            return header('HTTP/1.0 404 Not Found');
        }
        return header('HTTP/1.0 204 No Content');
    }

}
