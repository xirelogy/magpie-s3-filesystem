<?php

namespace MagpieLib\S3FileSystem;

use Magpie\Codecs\Parsers\StringParser;
use Magpie\Configurations\EnvKeySchema;
use Magpie\Configurations\EnvParserHost;
use Magpie\Facades\FileSystem\FileSystemConfig;
use Magpie\General\Factories\Annotations\FactoryTypeClass;

/**
 * Configuration for S3-compatible file system provider
 */
#[FactoryTypeClass(S3FileSystem::TYPECLASS, FileSystemConfig::class)]
class S3FileSystemConfig extends FileSystemConfig
{
    /**
     * @var string End-point
     */
    public readonly string $endPoint;
    /**
     * @var string Access key
     */
    public readonly string $accessKey;
    /**
     * @var string Access secret
     */
    public readonly string $accessSecret;
    /**
     * @var string Bucket
     */
    protected string $bucket = '';
    /**
     * @var string Region
     */
    protected string $region = '';


    /**
     * Constructor
     * @param string $endPoint
     * @param string $accessKey
     * @param string $accessSecret
     * @param string $bucket
     */
    public function __construct(string $endPoint, string $accessKey, string $accessSecret, string $bucket = '')
    {
        $this->endPoint = $endPoint;
        $this->accessKey = $accessKey;
        $this->accessSecret = $accessSecret;
        $this->bucket = $bucket;
    }


    /**
     * Specific bucket
     * @return string
     */
    public function getBucket() : string
    {
        return $this->bucket;
    }


    /**
     * Specific bucket
     * @param string $bucket
     * @return $this
     */
    public function withBucket(string $bucket) : static
    {
        $this->bucket = $bucket;
        return $this;
    }


    /**
     * Specific region
     * @return string
     */
    public function getRegion() : string
    {
        return $this->region;
    }


    /**
     * Specific region
     * @param string $region
     * @return $this
     */
    public function withRegion(string $region) : static
    {
        $this->region = $region;
        return $this;
    }


    /**
     * @inheritDoc
     */
    public static function getTypeClass() : string
    {
        return S3FileSystem::TYPECLASS;
    }


    /**
     * @inheritDoc
     */
    protected static function specificFromEnv(EnvParserHost $parserHost, EnvKeySchema $envKey) : static
    {
        $endPoint = $parserHost->requires($envKey->key('ENDPOINT'), StringParser::create());
        $key = $parserHost->requires($envKey->key('KEY'), StringParser::create());
        $secret = $parserHost->requires($envKey->key('SECRET'), StringParser::create());

        $ret = new static($endPoint, $key, $secret);

        $bucket = $parserHost->optional($envKey->key('BUCKET'), StringParser::create()->withEmptyAsNull());
        if ($bucket !== null) $ret->withBucket($bucket);

        $region = $parserHost->optional($envKey->key('REGION'), StringParser::create()->withEmptyAsNull());
        if ($region !== null) $ret->withRegion($region);

        return $ret;
    }
}