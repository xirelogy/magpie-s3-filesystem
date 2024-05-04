<?php

namespace MagpieLib\S3FileSystem;

/**
 * End-point style
 */
enum S3EndPointStyle : string
{
    /**
     * Subdomain end-point style (default)
     */
    case SUBDOMAIN = 'subdomain';
    /**
     * Path end-point style
     */
    case PATH = 'path';
}