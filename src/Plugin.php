<?php

declare(strict_types=1);

namespace Cx;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

final readonly class Plugin implements PluginInterface, Capable {
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => \Cx\CommandProvider::class,
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
