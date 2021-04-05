<?php

declare(strict_types = 1);

namespace ResumableJs;

use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
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
        ?LoggerInterface $logger = null
    ) {
        $this->request  = $request;
        $this->response = $response;

        $this->logger = $logger;

        $this->preProcess();
    }

    public function setResumableOption(array $resumableOption)
    {
        $this->resumableOption = array_merge($this->resumableOption, $resumableOption);
    }

    // sets original filename and extension, blah blah
    public function preProcess()
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
    public function isUploadComplete()
    {
        return $this->isUploadComplete;
    }

    /**
     * Set final filename.
     *
     * @param string Final filename
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getOriginalFilename($withoutExtension = false)
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
    public function getFilepath()
    {
        return $this->filepath;
    }

    /**
     * Get final extension.
     *
     * @return string Final extension name
     */
    public function getExtension()
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
    private function createSafeFilename($filename, $originalFilename)
    {
        $filename  = $this->removeExtension($filename);
        $extension = $this->findExtension($originalFilename);

        return sprintf('%s.%s', $filename, $extension);
    }

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
    private function createFileAndDeleteTmp($identifier, $filename)
    {
        $tmpFolder  = new Folder($this->tmpChunkDir($identifier));
        $chunkFiles = $tmpFolder->read(true, true, true)[1];

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
            $tmpFolder->delete();
            $this->uploadComplete = true;
        }
    }

    private function resumableParam($shortName)
    {
        $resumableParams = $this->resumableParams();
        if (!isset($resumableParams['resumable' . ucfirst($shortName)])) {
            return null;
        }
        return $resumableParams['resumable' . ucfirst($shortName)];
    }

    public function resumableParams()
    {
        $method = strtoupper($this->request->getMethod());
        if ($method === 'GET') {
            return $this->request->getQueryParams();
        } elseif ($method === 'POST') {
            return $this->request->getParsedBody();
        }
        return [];
    }

    public function isFileUploadComplete($filename, $identifier, $chunkSize, $totalSize)
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

    public function isChunkUploaded($identifier, $filename, $chunkNumber)
    {
        $chunkDir = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR;
        $file     = new File(
            $chunkDir . $this->tmpChunkFilename($filename, $chunkNumber)
        );
        return $file->exists();
    }

    public function tmpChunkDir($identifier)
    {
        $tmpChunkDir = $this->tempFolder . DIRECTORY_SEPARATOR . $identifier;
        if (!file_exists($tmpChunkDir)) {
            mkdir($tmpChunkDir, 0777, true);
        }
        return $tmpChunkDir;
    }

    public function tmpChunkFilename($filename, $chunkNumber)
    {
        return $filename . '.' . str_pad((string) $chunkNumber, 4, '0', STR_PAD_LEFT);
    }

    public function getExclusiveFileHandle($name)
    {
        // if the file exists, fopen() will raise a warning
        $previous_error_level = error_reporting();
        error_reporting(E_ERROR);
        $handle = fopen($name, 'x');
        error_reporting($previous_error_level);
        return $handle;
    }

    public function createFileFromChunks($chunkFiles, $destFile)
    {
        $this->log('Beginning of create files from chunks');

        natsort($chunkFiles);

        $handle = $this->getExclusiveFileHandle($destFile);
        if (!$handle) {
            return false;
        }

        $destFile         = new File($destFile);
        $destFile->handle = $handle;
        foreach ($chunkFiles as $chunkFile) {
            $file = new File($chunkFile);
            $destFile->append($file->read());

            $this->log('Append ', ['chunk file' => $chunkFile]);
        }

        $this->log('End of create files from chunks');
        return $destFile->exists();
    }

    public function setRequest($request)
    {
        $this->request = $request;
    }

    public function setResponse($response)
    {
        $this->response = $response;
    }

    private function log($msg, $ctx = [])
    {
        if ($this->debug && $this->logger !== null) {
            $this->logger->debug($msg, $ctx);
        }
    }

    private function findExtension($filename)
    {
        $parts = explode('.', basename($filename));

        return end($parts);
    }

    private function removeExtension($filename)
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
