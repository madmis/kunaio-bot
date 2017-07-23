#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use madmis\KunaBot\Command\BotCommand;

$container = new ContainerBuilder();
$loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/config'));
$loader->load('config.yml');
$loader->load('services.yml');

$application = new Application('Kuna.io Trading Bot', 'v0.0.1');
$command = new BotCommand();
$command->setContainer($container);
$application->add($command);
$application->run();