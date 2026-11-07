<?php
declare(strict_types=1);
namespace Zero;
require_once __DIR__.'/GalleryPages.php';

final class PinterestPages extends GalleryPages
{
    public function __construct(Config $config,Store $store,Api $api) { parent::__construct($config,$store,$api,'pinterest'); }
}
