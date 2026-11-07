<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryMedia.php';

final class TikTok extends GalleryMedia
{
    public const MAX_ITEMS=35;
    protected const SERVICE='tiktok', NAME='TikTok', PREFIX='tt', SESSION_COOKIE='sessionid', COOKIE_DOMAIN='tiktok.com';
    protected const SESSION_ALIASES=['sessionid_ss','sid_tt'];
}
