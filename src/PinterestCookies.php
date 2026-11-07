<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';

final class PinterestCookies extends GalleryCookies
{
    protected const PREFIX='pin_cookie', MEDIA=Pinterest::class;
    public const REPLIES=['cookie_pinterest','pin_cookie_upload','pin_cookie_view','pin_cookie_export','pin_cookie_remove','pin_cookie_confirm','pin_cookie_back'];
}
