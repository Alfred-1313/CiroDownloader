<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';
require_once __DIR__.'/YoutubeCheckerMedia.php';
final class YoutubeCheckerCookies extends GalleryCookies
{
    protected const PREFIX='ytc_cookie', MEDIA=YoutubeCheckerMedia::class, CODE='ytc';
    public const REPLIES=['cookie_youtube_checker','ytc_cookie_upload','ytc_cookie_view','ytc_cookie_export','ytc_cookie_remove','ytc_cookie_confirm','ytc_cookie_back'];
}
