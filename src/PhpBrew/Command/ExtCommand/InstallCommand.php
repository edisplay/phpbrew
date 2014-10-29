<?php
namespace PhpBrew\Command\ExtCommand;
use PhpBrew\Config;
use PhpBrew\Extension;
use PhpBrew\Extension\ExtensionManager;
use PhpBrew\Extension\ExtensionFactory;
use PhpBrew\Extension\PeclExtensionInstaller;
use PhpBrew\Extension\PeclExtensionDownloader;
use PhpBrew\Utils;

class InstallCommand extends \CLIFramework\Command
{
    public function usage()
    {
        return 'phpbrew [-dv, -r] ext install [extension name] [-- [options....]]';
    }

    public function brief()
    {
        return 'Install PHP extension';
    }

    /**
     * @param \GetOptionKit\OptionSpecCollection $opts
     */
    public function options($opts)
    {
        $opts->add('pv|php-version:', 'The php version for which we install the module.');
        $opts->add('pecl', 'Try to download from pecl even when ext source is bundled with php-src.');
    }

    public function arguments($args) {
        $args->add('extensions')
            ->suggestions(function() {
                $extdir = Config::getBuildDir() . '/' . Config::getCurrentPhpName() . '/ext';
                return array_filter(scandir($extdir), function($d) use ($extdir) {
                    return $d != '.' && $d != '..' && is_dir($extdir . DIRECTORY_SEPARATOR . $d);
                });
            })
            ;
    }


    protected function getExtConfig($args)
    {
        $version = 'stable';
        $options = array();

        if (count($args) > 0) {
            $pos = array_search('--', $args);
            if ($pos !== false) {
                $options = array_slice($args, $pos + 1);
            }

            if ($pos === false || $pos == 1) {
                $version = $args[0];
            }
        }
        return (object) array(
            'version' => $version,
            'options' => $options,
        );
    }

    public function execute($extName, $version = 'stable')
    {
        $extensions = array();

        if (Utils::startsWith($extName, '+')) {
            $config = Config::getConfigParam('extensions');
            $extName = ltrim($extName, '+');

            if (isset($config[$extName])) {
                foreach ($config[$extName] as $extensionName => $extOptions) {
                    $args = explode(' ', $extOptions);
                    $extensions[$extensionName] = $this->getExtConfig($args);
                }
            } else {
                $this->logger->info('Extension set name not found. Have you configured it at the config.yaml file?');
            }
        } else {
            $args = array_slice(func_get_args(), 1);
            $extensions[$extName] = $this->getExtConfig($args);
        }

        if ($this->options->{'php-version'} !== null) {
            $phpVersion = Utils::findLatestPhpVersion($this->options->{'php-version'});
            Config::setPhpVersion($phpVersion);
        }

        $manager = new ExtensionManager($this->logger);

        foreach ($extensions as $extensionName => $extConfig) {
            $ext = ExtensionFactory::lookup($extensionName);

            // Extension not found, use pecl to download it.
            if (!$ext) {
                $peclDownloader = new PeclExtensionDownloader($this->logger);
                $peclDownloader->download($extensionName, $extConfig->version);

                // Reload the extension
                $ext = ExtensionFactory::lookup($extensionName);
            }
            if (!$ext) {
                throw new Exception("$extensionName not found.");
            }
            $manager->installExtension($ext, $extConfig->version, $extConfig->options, $this->options->{'pecl'});
        }
        Config::useSystemPhpVersion();
    }
}
