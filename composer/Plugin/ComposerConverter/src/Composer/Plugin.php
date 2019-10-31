<?php

namespace Grasmash\ComposerConverter\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\ScriptEvents;
use Composer\Script\Event;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface {

  public function activate(Composer $composer, IOInterface $io) {

  }

  public function getCapabilities() {
    return array(
      ComposerCommandProvider::class => CommandProvider::class,
    );
  }

  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_UPDATE_CMD => 'tellThemTheyreWrong',
      ScriptEvents::POST_INSTALL_CMD => 'tellThemTheyreWrong',
    ];
  }

  public function tellThemTheyreWrong(Event $event) {
    $package = $event->getComposer()->getPackage();
    if ($package->getName() == 'drupal/drupal') {
      $event->getIO()->write('<info>You\'re using drupal/drupal and you shouldn\'t.</info>');
    }
  }

}
