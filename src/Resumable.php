<?php

declare(strict_types = 1);

namespace ResumableJs;

use Gaufrette\Filesystem;
use Gaufrette\Adapter\Local as LocalFilesystemAdapter;
use Gaufrette\StreamMode;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class Resumable
{
    /**
     * Debug is enabled
     * @var bool
     */
    protected $debug = false;

    public $tempFolder = 'tmp';

    public $uploadFolder = 'test/files/uploads';

    /**
     * For testing purposes
     *
     * @var bool
     * @internal Only used by tests, do not use it
     */
    public $deleteTmpFolder = true;

    /**
     * The request
     *
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * The response
     *
     * @var ResponseInterface
     */
    protected $response;

    protected $params;

    protected $chunkFile;

    /**
     * The logger
     *
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * The file system
     *
     * @var Filesystem
     */
    protected $fileSystem;

    protected $filename;

    protected $filepath;

    protected $extension;

    protected $originalFilename;

    protected $isUploadComplete = false;

    protected $resumableOption = [
        'identifier' => 'identifier',
        'filename' => 'filename',
        'chunkNumber' => 'chunkNumber',
        'chunkSize' => 'chunkSize',
        'totalSize' => 'totalSize'
    ];

    public const WITHOUT_EXTENSION = true;

    public function __construct(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?LoggerInterface $logger = null,
        ?Filesystem $fileSystem = null
    ) {
        $this->request    = $request;
        $this->response   = $response;
        $this->fileSystem = $fileSystem === null ? $this->getFileSystem() : $fileSystem;

        $this->logger = $logger;

        $this->preProcess();
    }

    protected function getFileSystem(): Filesystem
    {
        $cwd = getcwd();
        $cwd === false ? __DIR__ : $cwd;
        $adapter = new LocalFilesystemAdapter(
            $cwd
        );

        return new Filesystem($adapter);
    }

    public function setResumableOption(array $resumableOption): void
    {
        $this->resumableOption = array_merge($this->resumableOption, $resumableOption);
    }

    /**
     * sets original filename and extension, blah blah
     */
    public function preProcess(): void
    {
        if (!empty($this->resumableParams())) {
            if (!empty($this->request->getUploadedFiles())) {
                $this->extension        = $this->findExtension($this->resumableParam('filename'));
                $this->originalFilename = $this->resumableParam('filename');
            }
        }
    }

    /**
     * @return ResponseInterface|null
     */
    public function process()
    {
        if (!empty($this->resumableParams())) {
            if (!empty($this->request->getUploadedFiles())) {
                return $this->handleChunk();
            } else {
                return $this->handleTestChunk();
            }
        }
        return null;
    }

    /**
     * Get isUploadComplete
     *
     * @return bool
     */
    public function isUploadComplete(): bool
    {
        return $this->isUploadComplete;
    }

    /**
     * Set final filename.
     *
     * @param string Final filename
     */
    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getOriginalFilename(bool $withoutExtension = false): string
    {
        if ($withoutExtension === static::WITHOUT_EXTENSION) {
            return $this->removeExtension($this->originalFilename);
        }

        return $this->originalFilename;
    }

    /**
     * Get final filapath.
     *
     * @return string Final filename
     */
    public function getFilepath(): string
    {
        return $this->filepath;
    }

    /**
     * Get final extension.
     *
     * @return string Final extension name
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Makes sure the original extension never gets overridden by user defined filename.
     *
     * @param string User defined filename
     * @param string Original filename
     * @return string Filename that always has an extension from the original file
     */
    private function createSafeFilename(string $filename, string $originalFilename): string
    {
        $filename  = $this->removeExtension($filename);
        $extension = $this->findExtension($originalFilename);

        return sprintf('%s.%s', $filename, $extension);
    }

    /**
     * @return ResponseInterface
     */
    public function handleTestChunk()
    {
        $identifier  = $this->resumableParam($this->resumableOption['identifier']);
        $filename    = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = $this->resumableParam($this->resumableOption['chunkNumber']);

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            return $this->response->withStatus(204);
        } else {
            return $this->response->withStatus(200);
        }
    }

    /**
     * @return ResponseInterface
     */
    public function handleChunk()
    {
        /** @var \Psr\Http\Message\UploadedFileInterface $file */
        $file        = $this->request->getUploadedFiles()[0];
        $identifier  = $this->resumableParam($this->resumableOption['identifier']);
        $filename    = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = $this->resumableParam($this->resumableOption['chunkNumber']);
        $chunkSize   = $this->resumableParam($this->resumableOption['chunkSize']);
        $totalSize   = $this->resumableParam($this->resumableOption['totalSize']);

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            $chunkDir  = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR;
            $chunkFile = $chunkDir . $this->tmpChunkFilename($filename, $chunkNumber);

            $file->moveTo($chunkFile);
        }

        if ($this->isFileUploadComplete($filename, $identifier, $chunkSize, $totalSize)) {
            $this->isUploadComplete = true;
            $this->createFileAndDeleteTmp($identifier, $filename);
        }

        return $this->response->withStatus(200);
    }

    /**
     * Create the final file from chunks
     */
    private function createFileAndDeleteTmp(string $identifier, ?string $filename): void
    {
        $chunkDir   = $this->tmpChunkDir($identifier);
        $chunkFiles = $this->fileSystem->listKeys(
            $chunkDir
        )['keys'];
        // if the user has set a custom filename
        if (null !== $this->filename) {
            $finalFilename = $this->createSafeFilename($this->filename, $filename);
        } else {
            $finalFilename = $filename;
        }

        // replace filename reference by the final file
        $this->filepath  = $this->uploadFolder . DIRECTORY_SEPARATOR . $finalFilename;
        $this->extension = $this->findExtension($this->filepath);

        if ($this->createFileFromChunks($chunkFiles, $this->filepath) && $this->deleteTmpFolder) {
            $this->fileSystem->delete($chunkDir);
            $this->uploadComplete = true;
        }
    }

    /**
     * @return mixed|null
     */
    private function resumableParam(string $shortName)
    {
        $resumableParams = $this->resumableParams();
        if (!isset($resumableParams['resumable' . ucfirst($shortName)])) {
            return null;
        }
        return $resumableParams['resumable' . ucfirst($shortName)];
    }

    public function resumableParams(): array
    {
        $method = strtoupper($this->request->getMethod());
        if ($method === 'GET') {
            return $this->request->getQueryParams();
        } elseif ($method === 'POST') {
            return $this->request->getParsedBody();
        }
        return [];
    }

    public function isFileUploadComplete(string $filename, string $identifier, ?int $chunkSize, ?int $totalSize): bool
    {
        if ($chunkSize <= 0) {
            return false;
        }
        $numOfChunks = intval($totalSize / $chunkSize) + ($totalSize % $chunkSize == 0 ? 0 : 1);
        for ($i = 1; $i < $numOfChunks; $i++) {
            if (!$this->isChunkUploaded($identifier, $filename, $i)) {
                return false;
            }
        }
        return true;
    }

    public function isChunkUploaded(string $identifier, string $filename, int $chunkNumber): bool
    {
        $chunkDir = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR;
        return $this->fileSystem->has(
            $chunkDir . $this->tmpChunkFilename($filename, $chunkNumber)
        );
    }

    public function tmpChunkDir(string $identifier): string
    {
        return $this->tempFolder . DIRECTORY_SEPARATOR . $identifier;
    }

    /**
     * @param int|string $chunkNumber
     *
     * @example mock-file.png.0001 For a filename "mock-file.png"
     */
    public function tmpChunkFilename(string $filename, $chunkNumber): string
    {
        return $filename . '.' . str_pad((string) $chunkNumber, 4, '0', STR_PAD_LEFT);
    }

    public function createFileFromChunks(array $chunkFiles, string $destFile): bool
    {
        $this->log('Beginning of create files from chunks');

        natsort($chunkFiles);

        $stream = $this->fileSystem->createFile($destFile)->createStream();
        $stream->open(new StreamMode('x'));

        foreach ($chunkFiles as $chunkFile) {
            $stream->write($this->fileSystem->read($chunkFile));
            $this->log('Append ', ['chunk file' => $chunkFile]);
        }

        $stream->flush();
        $stream->close();

        $this->log('End of create files from chunks');
        return $this->fileSystem->has($destFile);
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    private function log(string $msg, array $ctx = []): void
    {
        if ($this->debug && $this->logger !== null) {
            $this->logger->debug($msg, $ctx);
        }
    }

    private function findExtension(string $filename): string
    {
        $parts = explode('.', basename($filename));

        return end($parts);
    }

    private function removeExtension(string $filename): string
    {
        $parts = explode('.', basename($filename));
        $ext   = end($parts); // get extension

        // remove extension from filename if any
        return str_replace(sprintf('.%s', $ext), '', $filename);
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

}
