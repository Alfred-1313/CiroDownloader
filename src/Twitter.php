<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

final class Twitter extends GalleryMedia
{
    protected const SERVICE='twitter', NAME='X', PREFIX='tw', SESSION_COOKIE='auth_token', COOKIE_DOMAIN='x.com';
}
