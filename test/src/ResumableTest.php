<?php

declare(strict_types = 1);

namespace ResumableJs\Test;

use ResumableJs\Resumable;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;

class ResumableTest extends TestCase
{
    /**
     * @var Resumable
     */
    protected $resumable;

    /**
     * @var \Psr\Http\Message\ServerRequestInterface
     */
    protected $request;

    /**
     * @var \Psr\Http\Message\ResponseInterface
     */
    protected $response;

    /**
     * @var Psr17Factory
     */
    protected $psr17Factory;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();

        $this->request = $this->psr17Factory->createServerRequest('GET', 'http://example.com');

        $this->response = $this->psr17Factory->createResponse(200);
    }

    public function tearDown(): void
    {
        unset($this->request);
        unset($this->response);
        parent::tearDown();
    }

    public function testProcessHandleChunk()
    {
        $resumableParams = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
            'resumableFilename' => 'mock.png',
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'POST',
            'http://example.com'
        )
            ->withParsedBody($resumableParams)
            ->withUploadedFiles(
                [
                new UploadedFile(
                    'mock.png',
                    27000, // Size
                    0 // Error status
                )
                ]
            );

        $this->resumable = $this->getMockBuilder(Resumable::class)
            ->setConstructorArgs([$this->request, $this->response])
            ->onlyMethods(['handleChunk'])
            ->getMock();

        $this->resumable->expects($this->once())
            ->method('handleChunk')
            ->willReturn(true);

        $this->assertNotNull($this->resumable->process());
    }

    public function testProcessHandleTestChunk()
    {
        $resumableParams = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
            'resumableFilename' => 'mock.png',
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams($resumableParams);

        $this->resumable = $this->getMockBuilder(Resumable::class)
            ->setConstructorArgs([$this->request, $this->response])
            ->onlyMethods(['handleTestChunk'])
            ->getMock();

        $this->resumable->expects($this->once())
            ->method('handleTestChunk')
            ->willReturn(true);

        $this->assertNotNull($this->resumable->process());
    }

    public function testHandleTestChunk()
    {
        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams(
            [
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
            'resumableFilename' => 'mock.png',
            'resumableRelativePath' => 'upload',
            ]
        );

        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test/tmp';
        $this->assertNotNull($this->resumable->handleTestChunk());
    }

    public function testHandleChunk()
    {
        $resumableParams = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
            'resumableFilename' => 'mock.txt',
            'resumableRelativePath' => 'upload',
        ];

        $uploadsDir = realpath(__DIR__ . '/../../test/uploads');

        $this->request = $this->psr17Factory->createServerRequest(
            'POST',
            'http://example.com'
        )
            ->withParsedBody($resumableParams)
            ->withUploadedFiles(
                [
                new UploadedFile(
                    $uploadsDir . '/mock.txt',
                    27000, // Size
                    0 // Error status
                )
                ]
            );

        $this->resumable                  = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder      = realpath(__DIR__ . '/../../test/tmp');
        $this->resumable->uploadFolder    = $uploadsDir;
        $this->resumable->deleteTmpFolder = false;
        $this->resumable->handleChunk();

        $this->assertFileExists('test/uploads/mock.txt');
        file_exists('test/tmp/identifier/mock.txt.003') && unlink('test/tmp/identifier/mock.txt.003');
        unlink('test/uploads/mock.txt');
    }

    public function testResumableParamsGetRequest()
    {
        $resumableParams = [
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 100,
            'resumableChunkSize' => 1000,
            'resumableIdentifier' => 100,
            'resumableFilename' => 'mock_file_name',
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams($resumableParams);

        $this->resumable = new Resumable($this->request, $this->response);
        $this->assertEquals('GET', $this->request->getMethod());
        $this->assertEquals($resumableParams, $this->request->getQueryParams());
        $this->assertEquals($resumableParams, $this->resumable->resumableParams());
    }

    public function isFileUploadCompleteProvider()
    {
        return [
            ['mock.png', 'files', 20, 60, true],
            ['mock.png','files', 25, 60, true],
            ['mock.png','files', 10, 60, false],
        ];
    }

    /**
     *
     * @dataProvider isFileUploadCompleteProvider
     */
    public function testIsFileUploadComplete($filename, $identifier, $chunkSize, $totalSize, $expected)
    {
        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test';
        $this->assertEquals(
            $expected,
            $this->resumable->isFileUploadComplete($filename, $identifier, $chunkSize, $totalSize)
        );
    }

    public function testIsChunkUploaded()
    {
        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test';
        $identifier                  = 'files';
        $filename                    = 'mock.png';
        $this->assertTrue($this->resumable->isChunkUploaded($identifier, $filename, 1));
        $this->assertFalse($this->resumable->isChunkUploaded($identifier, $filename, 10));
    }

    public function testTmpChunkDir()
    {
        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test';
        $identifier                  = 'mock-identifier';
        $expected                    = $this->resumable->tempFolder . DIRECTORY_SEPARATOR . $identifier;
        $this->assertEquals($expected, $this->resumable->tmpChunkDir($identifier));
        $this->assertFileExists($expected);
        rmdir($expected);
    }

    public function testTmpChunkFile()
    {
        $this->resumable = new Resumable($this->request, $this->response);
        $filename        = 'mock-file.png';
        $chunkNumber     = str_pad('1', 4, '0', STR_PAD_LEFT);
        $expected        = $filename . '.' . $chunkNumber;
        $this->assertEquals($expected, $this->resumable->tmpChunkFilename($filename, $chunkNumber));
    }

    public function testCreateFileFromChunks()
    {
        $files         = [
            'test/files/mock.png.0001',
            'test/files/mock.png.0002',
            'test/files/mock.png.0003',
        ];
        $totalFileSize = array_sum(
            [
            filesize('test/files/mock.png.0001'),
            filesize('test/files/mock.png.0002'),
            filesize('test/files/mock.png.0003')
            ]
        );
        $destFile      = 'test/files/5.png';

        $this->resumable = new Resumable($this->request, $this->response);
        $this->resumable->createFileFromChunks($files, $destFile);
        $this->assertFileExists($destFile);
        $this->assertEquals($totalFileSize, filesize($destFile));
        unlink('test/files/5.png');
    }

    public function testMoveUploadedFile()
    {
        $destFile        = 'test/files/4.png';
        $this->resumable = new Resumable($this->request, $this->response);
        $this->resumable->moveUploadedFile('test/files/mock.png.0001', $destFile);
        $this->assertFileExists($destFile);
        unlink($destFile);
    }

}
