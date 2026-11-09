<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core.php';
require dirname(__DIR__).'/src/Bot.php';
umask(0077);
$config=Zero\Config::load(); $store=new Zero\Store($config->storage);
(new Zero\RadioPlayerPanel(new Zero\Bot($config,$store,new Zero\Telegram($config))))->refresh();
