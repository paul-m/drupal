<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Script\ScriptEvents;
use Composer\Script\Event;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;

class ConverterPlugin implements PluginInterface, Capable, EventSubscriberInterface {

  public function activate(Composer $composer, IOInterface $io) {
    // No-op, necessary for interface.
  }

  public function getCapabilities() {
    return array(
      ComposerCommandProvider::class => CommandProvider::class,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_UPDATE_CMD => 'reconcileExtensions',
      ScriptEvents::POST_INSTALL_CMD => 'reconcileExtensions',
    ];
  }

  public function reconcileExtensions(Event $event) {
    // Going one up from vendor dir is the best we can do right now.
    $vendor_dir = $event->getComposer()->getConfig()->get('vendor-dir');
    $reconciler = new ExtensionReconciler($event->getComposer()->getPackage(), dirname($vendor_dir));
    if (!empty(array_merge($reconciler->getUnreconciledPackages(), $reconciler->getExoticPackages()))) {
      error_log(print_r(array_merge($reconciler->getUnreconciledPackages(), $reconciler->getExoticPackages()), true));
      $event->getIO()->write([
        '  This Drupal installation has extensions which are not present in',
        '  composer.json. Run `composer drupal-legacy-convert --dry-run` to',
        '  display them.',
      ]);
    }
  }

}
