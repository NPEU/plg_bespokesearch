<?php
defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

use NPEU\Plugin\Finder\BespokeSearch\Extension\BespokeSearch;

return new class () implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container)
            {
                $dispatcher = $container->get(DispatcherInterface::class);
                $database   = $container->get(DatabaseInterface::class);
                $config  = (array) PluginHelper::getPlugin('finder', 'bespokesearch');

                $app = Factory::getApplication();

                /** @var \Joomla\CMS\Plugin\CMSPlugin $plugin */
                $plugin = new BespokeSearch(
                    $dispatcher,
                    $config,
                    $database
                );
                $plugin->setApplication($app);
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};