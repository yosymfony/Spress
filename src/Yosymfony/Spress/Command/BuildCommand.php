<?php

/*
 * This file is part of the Yosymfony\Spress.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
 
namespace Yosymfony\Spress\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Yosymfony\ResourceWatcher\ResourceWatcher;
use Yosymfony\ResourceWatcher\ResourceCacheMemory;
use Yosymfony\Spress\IO\ConsoleIO;
use Yosymfony\Spress\HttpServer\HttpServer;

class BuildCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('site:build')
            ->setDescription('Build your site')
            ->addOption(
                'source',
                's',
                InputOption::VALUE_REQUIRED,
                'Directory where Spress will read your files'
            )
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_REQUIRED,
                'Timezone for the site generator'
            )
            ->addOption(
                'env',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the environment configuration'
            )
            ->addOption(
                'watch',
                null,
                InputOption::VALUE_NONE,
                'Watching for changes and regenerate automatically your site'
            )
            ->addOption(
                'server',
                null,
                InputOption::VALUE_NONE,
                'Start the built-in server'
            )
            ->addOption(
                'drafts',
                null,
                InputOption::VALUE_NONE,
                'Parse your draft post'
            )
            ->addOption(
                'safe',
                null,
                InputOption::VALUE_NONE,
                'Disable your template plugins'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timezone = $input->getOption('timezone');
        $drafts = $input->getOption('drafts');
        $safe = $input->getOption('safe');
        $env = $input->getOption('env');
        $server = $input->getOption('server');
        $watch = $input->getOption('watch');
        $sourceDir = $input->getOption('source');
        
        $io = new ConsoleIO($input, $output, $this->getHelperSet());
        $app = new SpressCLI($io);
        
        $config = $app['spress.config'];
        $envDefault = $config->getEnvironmentName();
        
        $parse = function() use ($app, $sourceDir, $env, $timezone, $drafts, $safe)
        {
            return $app->parse(
                $sourceDir,
                $env,
                $timezone,
                $drafts,
                $safe);
        };
        
        $io->write('<comment>Starting...</comment>');
        $io->write(sprintf('<comment>Environment: %s.</comment>', $env ? $env : $envDefault));
        
        if($drafts)
        {
            $io->write('<comment>Posts drafts enabled.</comment>');
        }
        
        if($safe)
        {
            $io->write('<comment>Plugins disabled.</comment>');
        }
        
        $resultData = $parse();
        
        $this->resultMessage($io, $resultData);
        
        if($server)
        {
            $port = $config->getRepository()->get('port');
            $host = $config->getRepository()->get('host');
            $contentLocator = $app['spress.content_locator'];
            $twigFactory = $app['spress.twig_factory'];
            $documentroot = $contentLocator->getDestinationDir();
            $sourceDir = $contentLocator->getSourceDir();
            $destinationDir = $contentLocator->getDestinationDir();
            $serverroot = $app['spress.paths']['http_server_root'];
            $rw = $this->buildResourceWatcher($sourceDir, $destinationDir);
            
            $server = new HttpServer($io, $twigFactory, $serverroot, $documentroot, $port, $host);
            
            if($watch)
            {
                $io->write('<comment>Auto-regeneration: enabled.</comment>');
                
                $server->onBeforeHandleRequest(function($request, $io) use ($rw, $parse)
                {
                    $rw->findChanges();
                    
                    if($rw->hasChanges())
                    {
                        $io->write('<comment>Rebuilding site...</comment>');
                        
                        $parse();
                        
                        $io->write('<comment>Site ready.</comment>');
                    }
                });
            }
            
            $server->start();
        }
    }
    
    private function buildResourceWatcher($sourceDir, $destinationDir)
    {
        $finder = new Finder();
        $finder->files()
            ->name('*.*')
            ->in($sourceDir)
            ->exclude($destinationDir);

        $rc = new ResourceCacheMemory();
        
        $rw = new ResourceWatcher($rc);
        $rw->setFinder($finder);
        
        return $rw;
    }
    
    private function resultMessage(ConsoleIO $io, array $resultData)
    {
        $io->write(sprintf('Total posts: %d', $resultData['total_post']));
        $io->write(sprintf('Processed posts: %d', $resultData['processed_post']));
        $io->write(sprintf('Drafts post: %d', $resultData['drafts_post']));
        $io->write(sprintf('Total pages: %d', $resultData['total_pages']));
        $io->write(sprintf('Processed pages: %d', $resultData['processed_pages']));
        $io->write(sprintf('Other resources: %d', $resultData['other_resources']));
    }
}
