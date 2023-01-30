<?php

declare(strict_types=1);

namespace Dilab;

use Gaufrette\Filesystem;
use Gaufrette\Adapter\Local as LocalFilesystemAdapter;
use Gaufrette\StreamMode;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class Resumable
{

    public const HTTP_OK = 200;
    public const HTTP_NO_CONTENT = 204;

    protected bool $debug = false;

    public string $tempFolder = 'tmp';

    public string $uploadFolder = 'test/files/uploads';

    /**
     * For testing purposes
     *
     * @var bool
     * @internal Only used by tests, do not use it
     */
    public $deleteTmpFolder = true;

    protected ServerRequestInterface $request;

    protected ResponseInterface $response;

    protected $params;

    protected $chunkFile;

    protected ?LoggerInterface $logger;

    protected Filesystem $fileSystem;

    protected ?string $filename = null;

    protected ?string $filepath = null;

    protected ?string $extension = null;

    protected ?string $originalFilename = null;

    protected bool $isUploadComplete = false;

    protected array $resumableOption = [
        'identifier'  => 'identifier',
        'filename'    => 'filename',
        'chunkNumber' => 'chunkNumber',
        'chunkSize'   => 'chunkSize',
        'totalSize'   => 'totalSize'
    ];

    public const WITHOUT_EXTENSION = true;

    public function __construct(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        ?LoggerInterface       $logger = null,
        ?Filesystem            $fileSystem = null
    )
    {
        $this->request    = $request;
        $this->response   = $response;
        $this->fileSystem = $fileSystem ?? $this->getFileSystem();

        $this->logger = $logger;

        $this->preProcess();
    }

    protected function getFileSystem(): Filesystem
    {
        $cwd     = getcwd();
        $cwd     = $cwd === false ? __DIR__ : $cwd;
        $adapter = new LocalFilesystemAdapter($cwd);

        return new Filesystem($adapter);
    }

    public function setResumableOption(array $resumableOption): void
    {
        $this->resumableOption = array_merge($this->resumableOption, $resumableOption);
    }

    /**
     * Sets the initial parameters for the file download if they are missing
     *
     * @return void
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

    public function process(): ?ResponseInterface
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

    public function isUploadComplete(): bool
    {
        return $this->isUploadComplete;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getOriginalFilename(bool $withoutExtension = false): string
    {
        if ($withoutExtension === static::WITHOUT_EXTENSION) {
            return $this->removeExtension($this->originalFilename);
        }

        return $this->originalFilename;
    }

    public function getFilepath(): string
    {
        return $this->filepath;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Ensures that the original file extension never gets overridden by user
     * defined filenames.
     *
     * @param string $filename         User defined filename
     * @param string $originalFilename Original filename
     *
     * @return string Filename which always has the same extension as the original file
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
    public function handleTestChunk(): ResponseInterface
    {
        $identifier  = $this->resumableParam($this->resumableOption['identifier']);
        $filename    = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = $this->resumableParam($this->resumableOption['chunkNumber']);

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            return $this->response->withStatus(self::HTTP_NO_CONTENT);
        }

        return $this->response->withStatus(self::HTTP_OK);
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

        return $this->response->withStatus(self::HTTP_OK);
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
        }

        if ($method === 'POST') {
            return $this->request->getParsedBody();
        }

        return [];
    }

    public function isFileUploadComplete(string $filename, string $identifier, ?int $chunkSize, ?int $totalSize): bool
    {
        if ($chunkSize <= 0) {
            return false;
        }
        $numOfChunks = (int) ($totalSize / $chunkSize) + ($totalSize % $chunkSize === 0 ? 0 : 1);
        for ($chunkNumber = 1; $chunkNumber < $numOfChunks; $chunkNumber++) {
            if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
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

        // create destination file to steam the chunks into
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
