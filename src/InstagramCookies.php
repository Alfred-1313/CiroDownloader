<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';

final class InstagramCookies extends GalleryCookies
{
    protected const PREFIX='ig_cookie', MEDIA=Instagram::class;
    public const REPLIES=['cookie_instagram','ig_cookie_upload','ig_cookie_view','ig_cookie_export','ig_cookie_remove','ig_cookie_confirm','ig_cookie_back'];
}
