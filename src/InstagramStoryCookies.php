<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryCookies.php';
require_once __DIR__.'/InstagramStories.php';

final class InstagramStoryCookies extends GalleryCookies
{
    protected const PREFIX='igs_cookie', MEDIA=InstagramStories::class, CODE='igs';
    public const REPLIES=['cookie_instagram_stories','igs_cookie_upload','igs_cookie_view','igs_cookie_export','igs_cookie_remove','igs_cookie_confirm','igs_cookie_back'];
}
