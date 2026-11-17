<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

/** Checker scans, covers and deliveries never borrow the manual YouTube session. */
final class YoutubeCheckerMedia extends GalleryMedia
{
    protected const SERVICE='youtube';
    public static function config(Config $config): Config
    {
        $copy=clone $config;
        $copy->youtubeCookies=$config->storage.'/youtube-checker-cookies.txt';
        return $copy;
    }
    public static function pool(Config $config): CookiePool { return youtubeCookiePool(self::config($config)); }
    public static function validateCookies(string $content): void { validateYouTubeCookies($content); }
    public static function cookieIdentity(string $content): string { return youtubeCookieIdentity($content); }
}
