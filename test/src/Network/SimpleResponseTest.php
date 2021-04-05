<?php

declare(strict_types = 1);

namespace ResumableJs\Network;

use PHPUnit\Framework\TestCase;

/**
 * Class SimpleResponseTest
 * @property $response Response
 */
class SimpleResponseTest extends TestCase
{

    protected function setUp(): void
    {
        $this->response = new SimpleResponse();
    }

    public function tearDown(): void
    {
        unset($this->response);
        parent::tearDown();
    }

    public function headerProvider()
    {
        return [
            [404,404],
            [204,204],
            [200,200],
            [500,204],
        ];
    }

    /**
     * @runInSeparateProcess
     * @dataProvider headerProvider
     */
    public function testHeader($statusCode, $expectd)
    {
        $this->response->header($statusCode);
        $this->assertEquals($expectd, http_response_code());
    }

}
