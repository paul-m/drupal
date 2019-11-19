<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Script\Event;

class ConverterPlugin implements Capable, EventSubscriberInterface, PluginInterface {

  /**
   * The Compsoer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * The IO object.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  public function getCapabilities() {
    return [
      ComposerCommandProvider::class => CommandProvider::class,
    ];
  }

  public static function getSubscribedEvents(): array {
    return [
      'post-install-cmd' => ['notifyUnreconciledExtensions'],
      'post-update-cmd' => ['notifyUnreconciledExtensions'],
    ];
  }

  public static function notifyUnreconciledExtensions(Event $event) {
    $notifier = new UnreconciledNotifier($event->getComposer(), $event->getIO());
    $notifier->notify();
  }

}
