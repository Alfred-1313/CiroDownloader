<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

/** Independent authenticated pool shared by checker scans and checker deliveries. */
final class InstagramCheckerMedia extends GalleryMedia
{
    protected static function cookiePath(Config $config): string { return $config->storage.'/instagram-checker-cookies.txt'; }
}
