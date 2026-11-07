<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';

final class TwitterCookies extends GalleryCookies
{
    protected const PREFIX='x_cookie', MEDIA=Twitter::class;
    public const REPLIES=['cookie_twitter','x_cookie_upload','x_cookie_view','x_cookie_export','x_cookie_remove','x_cookie_confirm','x_cookie_back'];
}
