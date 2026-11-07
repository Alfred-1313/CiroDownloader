<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

final class Pinterest extends GalleryMedia
{
    protected const SERVICE='pinterest', NAME='Pinterest', PREFIX='pin', SESSION_COOKIE='_pinterest_sess', COOKIE_DOMAIN='pinterest.com';
}
