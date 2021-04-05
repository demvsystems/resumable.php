<?php

declare(strict_types = 1);

namespace ResumableJs\Network;

interface Response
{

    /**
     * @param $statusCode
     * @return mixed
     */
    public function header($statusCode);

}
