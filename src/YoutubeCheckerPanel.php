<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/InstagramCheckerPanel.php';
require_once __DIR__.'/YoutubeChecker.php';
final class YoutubeCheckerPanel extends InstagramCheckerPanel
{
    protected const SERVICE='youtube', CALLBACK='yc', CHECKER=YoutubeChecker::class;
    public static function command(string $text): ?array
    {
        if(!preg_match('~^/(?:youtube_checker|yt_checker)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~sD',trim($text),$m)) return null;
        return ['bot'=>$m[1]??'','argument'=>trim($m[2]??'')];
    }
    protected function openCookies(int $chat,int $owner): void { (new Cookies($this->bot))->handle($chat,$owner,'cookie_youtube'); }
}
