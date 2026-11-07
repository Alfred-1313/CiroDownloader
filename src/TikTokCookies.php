<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';

final class TikTokCookies extends GalleryCookies
{
    protected const PREFIX='tt_cookie', MEDIA=TikTok::class;
    public const REPLIES=['cookie_tiktok','tt_cookie_upload','tt_cookie_view','tt_cookie_export','tt_cookie_remove','tt_cookie_confirm','tt_cookie_back'];
}
