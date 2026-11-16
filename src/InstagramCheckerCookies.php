<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';
require_once __DIR__.'/InstagramCheckerMedia.php';
final class InstagramCheckerCookies extends GalleryCookies
{
    protected const PREFIX='igc_cookie', MEDIA=InstagramCheckerMedia::class, CODE='igc';
    public const REPLIES=['cookie_instagram_checker','igc_cookie_upload','igc_cookie_view','igc_cookie_export','igc_cookie_remove','igc_cookie_confirm','igc_cookie_back'];
}
