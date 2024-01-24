<?php

namespace MagpieLib\S3FileSystem;

use Aws\S3\Exception\S3Exception;
use Aws\S3\Exception\S3MultipartUploadException;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Exception;
use Magpie\Exceptions\GeneralPersistenceException;
use Magpie\Exceptions\InvalidDataException;
use Magpie\Exceptions\NotOfTypeException;
use Magpie\Exceptions\PersistenceException;
use Magpie\Exceptions\SafetyCommonException;
use Magpie\Exceptions\StreamException;
use Magpie\Exceptions\StreamReadFailureException;
use Magpie\Exceptions\StreamWriteFailureException;
use Magpie\Facades\FileSystem\FileSystem;
use Magpie\Facades\FileSystem\FileSystemConfig;
use Magpie\Facades\Log;
use Magpie\Facades\Mime\Mime;
use Magpie\General\Concepts\BinaryContentable;
use Magpie\General\Concepts\BinaryDataProvidable;
use Magpie\General\Contents\SimpleBinaryContent;
use Magpie\General\Factories\Annotations\FactoryTypeClass;
use Magpie\General\Factories\ClassFactory;
use Magpie\General\Names\CommonHttpStatusCode;
use Magpie\System\Kernel\BootContext;
use Magpie\System\Kernel\BootRegistrar;

/**
 * S3-compatible file system provider
 */
#[FactoryTypeClass(S3FileSystem::TYPECLASS, FileSystem::class)]
class S3FileSystem extends FileSystem
{
    /**
     * Current type class
     */
    public const TYPECLASS = 's3';

    /**
     * @var S3FileSystemConfig Associated configuration
     */
    protected readonly S3FileSystemConfig $config;
    /**
     * @var S3ClientInterface Associated client
     */
    protected S3ClientInterface $client;


    /**
     * Constructor
     * @param S3FileSystemConfig $config
     */
    protected function __construct(S3FileSystemConfig $config)
    {
        parent::__construct();

        $this->config = $config;

        $args = [
            'version' => 'latest',
            'endpoint' => $config->endPoint,
            'region' => $config->getRegion(),
            'credentials' => [
                'key' => $config->accessKey,
                'secret' => $config->accessSecret,
            ],
        ];

        $this->client = new S3Client($args);
    }


    /**
     * @inheritDoc
     */
    public function isFileExist(string $path) : bool
    {
        try {
            $effPath = $this->acceptPath($path);
        } catch (Exception) {
            return false;
        }

        return $this->client->doesObjectExist($this->config->getBucket(), $effPath);
    }


    /**
     * @inheritDoc
     */
    public function readFile(string $path, array $options = []) : BinaryDataProvidable
    {
        $object = $this->getObjectArray($path);
        if (!array_key_exists('Body', $object)) throw new StreamReadFailureException();

        $mimeType = $object['ContentType'] ?? null;
        $data = $object['Body']->getContents();
        return SimpleBinaryContent::create($data, $mimeType);
    }


    /**
     * @inheritDoc
     */
    public function writeFile(string $path, BinaryDataProvidable|string $data, array $options = []) : void
    {
        $mimeType = $data instanceof BinaryContentable ? $data->getMimeType() : null;
        $data = $data instanceof BinaryDataProvidable ? $data->getData() : $data;
        $this->uploadObject($path, $data, $mimeType);
    }


    /**
     * @inheritDoc
     */
    public function deleteFile(string $path) : bool
    {
        if (!$this->isFileExist($path)) return false;

        $command = $this->client->getCommand('deleteObject', [
            'Bucket' => $this->config->getBucket(),
            'Key' => $this->acceptPath($path),
        ]);

        try {
            $this->client->execute($command);
        } catch (Exception) {
            return false;
        }

        return !$this->isFileExist($path);
    }


    /**
     * @inheritDoc
     */
    public function isDirectoryExist(string $path) : bool
    {
        try {
            $command = $this->client->getCommand('listObjects', [
                'Bucket' => $this->config->getBucket(),
                'Prefix' => rtrim($this->acceptPath($path), '/') . '/',
                'MaxKeys' => 1,
            ]);

            $response = $this->client->execute($command);
            return $response['Contents'] || $response['CommonPrefixes'];
        } catch (S3Exception $ex) {
            // Check for proper error code when not exist
            if (in_array($ex->getStatusCode(), [CommonHttpStatusCode::FORBIDDEN, CommonHttpStatusCode::NOT_FOUND], true)) {
                return false;
            }

            Log::warning($ex->getMessage());
            return false;
        } catch (Exception $ex) {
            Log::warning($ex->getMessage());
            return false;
        }
    }


    /**
     * @inheritDoc
     */
    public function createDirectory(string $path) : bool
    {
        if (!str_ends_with($path, '/')) $path = "$path/";

        try {
            $this->uploadObject($path, '', null);
            return true;
        } catch (Exception) {
            return false;
        }
    }


    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path, bool $isEmpty = true) : bool
    {
        if (!$this->isDirectoryExist($path)) return false;

        try {
            $key = $this->acceptPath($path) . '/';
            $this->client->deleteMatchingObjects($this->config->getBucket(), $key);
        } catch (Exception) {
            return false;
        }

        return true;
    }


    /**
     * Try to upload object (directory/file)
     * @param string $path
     * @param string $body
     * @param string|null $mimeType
     * @return void
     * @throws SafetyCommonException
     * @throws PersistenceException
     * @throws StreamException
     */
    private function uploadObject(string $path, string $body, ?string $mimeType) : void
    {
        _throwable() ?? throw new GeneralPersistenceException();

        $key = $this->acceptPath($path);
        $isDirectory = str_ends_with($path, '/');

        $s3Options = [];

        if ($isDirectory) {
            $key .= '/';
        } else {
            $s3Options['ContentType'] = $mimeType !== null ? $mimeType : static::detectMimeType($key, $body);
            $s3Options['ContentLength'] = strlen($body);
        }

        $acl = 'private';

        try {
            $this->client->upload($this->config->getBucket(), $key, $body, $acl, ['params' => $s3Options]);
        } catch (S3MultipartUploadException $ex) {
            throw new StreamWriteFailureException(previous: $ex);
        }
    }


    /**
     * Try to retrieve object as array
     * @param string $path
     * @return array
     * @throws InvalidDataException
     * @throws StreamException
     */
    private function getObjectArray(string $path) : array
    {
        $s3Options = [
            'Bucket' => $this->config->getBucket(),
            'Key' => $this->acceptPath($path),
            '@http' => [
                'stream' => true,
            ],
        ];

        $command = $this->client->getCommand('getObject', $s3Options);

        try {
            $response = $this->client->execute($command);
        } catch (Exception $ex) {
            throw new StreamReadFailureException(previous: $ex);
        }

        return $response->toArray();
    }


    /**
     * Accept path into a location
     * @param string $path
     * @return string
     * @throws InvalidDataException
     */
    private function acceptPath(string $path) : string
    {
        $path = $this->checkPath($path);
        if ($path === null) throw new InvalidDataException();

        return ltrim($path, '/');
    }


    /**
     * Detect MIME type
     * @param string $path
     * @param string $content
     * @return string
     */
    protected static function detectMimeType(string $path, string $content) : string
    {
        return Mime::resolveMimeType($path, $content) ?? 'text/plain';
    }


    /**
     * @inheritDoc
     */
    public static function getTypeClass() : string
    {
        return static::TYPECLASS;
    }


    /**
     * @inheritDoc
     */
    protected static function specificInitialize(FileSystemConfig $config) : static
    {
        if (!$config instanceof S3FileSystemConfig) throw new NotOfTypeException($config, S3FileSystemConfig::class);

        return new static($config);
    }


    /**
     * @inheritDoc
     */
    public static function systemBootRegister(BootRegistrar $registrar) : bool
    {
        return true;
    }


    /**
     * @inheritDoc
     */
    public static function systemBoot(BootContext $context) : void
    {
        ClassFactory::includeDirectory(__DIR__);
    }
}