<?php

declare(strict_types = 1);

namespace ResumableJs\Network;

use PHPUnit\Framework\TestCase;

/**
 * Class SimpleRequestTest
 * @property $request Request
 */
class SimpleRequestTest extends TestCase
{

    protected function setUp(): void
    {
        $this->request = new SimpleRequest();
    }

    public function tearDown(): void
    {
        unset($this->request);
        parent::tearDown();
    }

    public function testIsPost()
    {
        $_POST = [
           'resumableChunkNumber' => 3,
           'resumableTotalChunks' => 600,
           'resumableChunkSize' => 200,
           'resumableIdentifier' => 'identifier',
           'resumableFilename' => 'mock.png',
           'resumableRelativePath' => 'upload',
        ];
        $this->assertTrue($this->request->is('post'));
        unset($_POST);
    }

    public function testIsGet()
    {
        $_GET = [
           'resumableChunkNumber' => 3,
           'resumableTotalChunks' => 600,
           'resumableChunkSize' => 200,
           'resumableIdentifier' => 'identifier',
           'resumableFilename' => 'mock.png',
           'resumableRelativePath' => 'upload',
        ];
        $this->assertTrue($this->request->is('get'));
        unset($_GET);
    }

    public function testData()
    {
        $data = [
           'resumableChunkNumber' => 3,
           'resumableTotalChunks' => 600,
           'resumableChunkSize' => 200,
           'resumableIdentifier' => 'identifier',
           'resumableFilename' => 'mock.png',
           'resumableRelativePath' => 'upload',
        ];

        $_GET  = $data;
        $_POST = $data;

        $this->assertEquals($data, $this->request->data('get'));
        $this->assertEquals($data, $this->request->data('post'));

        unset($_GET);
        unset($_POST);
    }

    public function testFile()
    {
        $file = [
           'name' => 'mock.png',
           'type' => 'application/octet-stream',
           'tmp_name' => 'test/files/mock.png.0003',
           'error' => 0,
           'size' => 1048576,
        ];

        $_FILES['file'] = $file;
        $this->assertEquals($file, $this->request->file());
        unset($_FILES);
    }

}
