<?php

namespace MagpieLib\S3FileSystem\Codecs\Parsers;

use Magpie\Codecs\Parsers\EnumParser;
use MagpieLib\S3FileSystem\S3EndPointStyle;

/**
 * End-point style parser
 */
class S3EndPointStyleParser extends EnumParser
{
    /**
     * @inheritDoc
     */
    protected static function getEnumClassName() : string
    {
        return S3EndPointStyle::class;
    }
}