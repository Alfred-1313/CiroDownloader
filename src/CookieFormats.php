<?php
declare(strict_types=1);
namespace Zero;

/**
 * Turns the exports that browsers and extensions actually produce into the
 * Netscape text every downloader reads: a Netscape file (with or without its
 * first line), a JSON list of cookies (Cookie-Editor, EditThisCookie) or a
 * "name=value; name=value" header string. Phones usually offer only the last two.
 * The result is validated afterwards by the service's own rules, so a
 * converted export can never be used for another site.
 */
final class CookieFormats
{
    public const MAX_COOKIES=2000;
    /** A pasted message is only treated as cookies when it plausibly is one. */
    public static function looksLikeCookies(string $text): bool
    {
        $text=trim($text);
        if(strlen($text)<24||strlen($text)>CookiePool::MAX_BYTES) return false;
        if(str_starts_with($text,'[')||str_starts_with($text,'{')||str_starts_with($text,'# Netscape')||str_starts_with($text,'# HTTP Cookie File')) return true;
        if(str_contains($text,"\t")&&preg_match('/^[^\n]*\t(?:TRUE|FALSE)\t/m',$text)) return true;
        return preg_match('/^[^\s=;]+=[^;\n]*(?:;\s*[^\s=;]+=[^;\n]*)+$/D',$text)===1;
    }
    /** @throws \RuntimeException when the text is none of the supported formats */
    public static function toNetscape(string $content,string $domain=''): string
    {
        $content=preg_replace('/^\xEF\xBB\xBF/','',$content)??$content;
        $content=trim(str_replace(["\r\n","\r"],"\n",$content))."\n";
        $first=ltrim($content);
        if($first[0]==='['||$first[0]==='{') return self::fromJson($content);
        if(preg_match('/^[^\n]*\t(?:TRUE|FALSE)\t/m',$content)||str_starts_with($first,'#')) return self::fromNetscape($content);
        return self::fromHeader($content,$domain);
    }
    private static function fromNetscape(string $content): string
    {
        // Some tools omit the first line that downloaders require.
        if(!preg_match('/^# (?:Netscape )?HTTP Cookie File/',$content)) $content="# Netscape HTTP Cookie File\n".$content;
        return $content;
    }
    private static function row(string $domain,bool $includeSubdomains,string $path,bool $secure,int $expires,string $name,string $value,bool $httpOnly): string
    {
        foreach([$domain,$path,$name,$value] as $field) if(preg_match('/[\x00-\x08\x0A-\x1F\x7F\t]/',$field)) throw new \RuntimeException('Invalid cookie field');
        if($domain===''||$name==='') throw new \RuntimeException('Invalid cookie field');
        return ($httpOnly?'#HttpOnly_':'').$domain."\t".($includeSubdomains?'TRUE':'FALSE')."\t".($path!==''?$path:'/')."\t".($secure?'TRUE':'FALSE')."\t".max(0,$expires)."\t".$name."\t".$value;
    }
    private static function fromJson(string $content): string
    {
        try { $data=json_decode($content,true,16,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new \RuntimeException('Invalid cookie JSON'); }
        // Some extensions wrap the list: {"cookies":[...]}.
        if(is_array($data)&&isset($data['cookies'])&&is_array($data['cookies'])) $data=$data['cookies'];
        if(!is_array($data)||!array_is_list($data)||!$data||count($data)>self::MAX_COOKIES) throw new \RuntimeException('Invalid cookie JSON');
        $rows=["# Netscape HTTP Cookie File"];
        foreach($data as $cookie) {
            if(!is_array($cookie)||!isset($cookie['name'],$cookie['domain'])||!is_string($cookie['name'])||!is_string($cookie['domain'])||!(is_string($cookie['value']??'')||is_int($cookie['value']??0))) throw new \RuntimeException('Invalid cookie JSON');
            $domain=strtolower($cookie['domain']);
            // A host-only cookie has no leading dot and does not cover subdomains.
            $hostOnly=array_key_exists('hostOnly',$cookie)?(bool)$cookie['hostOnly']:!str_starts_with($domain,'.');
            if(!$hostOnly&&!str_starts_with($domain,'.')) $domain='.'.$domain;
            $expires=0;
            foreach(['expirationDate','expires','expiry','expiration'] as $name) if(isset($cookie[$name])&&is_numeric($cookie[$name])) { $expires=(int)floor((float)$cookie[$name]); break; }
            if(!empty($cookie['session'])) $expires=0;
            $rows[]=self::row($domain,!$hostOnly,(string)($cookie['path']??'/'),!empty($cookie['secure']),$expires,$cookie['name'],(string)($cookie['value']??''),!empty($cookie['httpOnly']));
        }
        return implode("\n",$rows)."\n";
    }
    private static function fromHeader(string $content,string $domain): string
    {
        $line=trim($content);
        if($domain===''||str_contains($line,"\n")||!preg_match('/^[^\s=;]+=[^;]*(?:;\s*[^\s=;]+=[^;]*)*;?$/D',$line)) throw new \RuntimeException('Unrecognized cookie format');
        $rows=["# Netscape HTTP Cookie File"]; $count=0;
        foreach(explode(';',$line) as $pair) {
            $pair=trim($pair); if($pair==='') continue;
            [$name,$value]=explode('=',$pair,2);
            // A header string carries no domain, path or lifetime; treat it as a session cookie of the site.
            $rows[]=self::row('.'.$domain,true,'/',true,0,$name,$value,false);
            if(++$count>self::MAX_COOKIES) throw new \RuntimeException('Too many cookies');
        }
        return implode("\n",$rows)."\n";
    }
}
