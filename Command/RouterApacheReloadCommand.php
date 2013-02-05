<?php

namespace Theodo\Evolution\RouterBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;

use Theodo\Evolution\RouterBundle\Dumper\ApacheMatcherDumper;

/**
 * RouterApacheDumperCommand.
 *
 * @author  <fabien@symfony.com>
 */
class RouterApacheReloadCommand extends ContainerAwareCommand
{
    /**
     * {@inheritDoc}
     */
    public function isEnabled()
    {
        if (!$this->getContainer()->has('router')) {
            return false;
        }
        $router = $this->getContainer()->get('router');
        if (!$router instanceof RouterInterface) {
            return false;
        }

        return parent::isEnabled();
    }

    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setName('router:reload-apache-routes')
            ->setDefinition(array(
            new InputArgument('script_name', InputArgument::OPTIONAL, 'The script name of the application\'s front controller.'),
            new InputOption('console-output', 'c', InputOption::VALUE_NONE, 'Output the .htacces content directly into the console (does not affect the .htaccess file)'),
            new InputOption('base-uri', null, InputOption::VALUE_REQUIRED, 'The base URI'),
        ))
            ->setDescription('Rebuild .htaccess from with the application\'s routes')
            ->setHelp(<<<EOF
The <info>%command.name%</info> updates .htacess file with the application's routes.
The --console-output otpion allows to output them only into the console

  <info>php %command.full_name%</info>
EOF
        )
        ;
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $router = $this->getContainer()->get('router');

        $dumpOptions = array();
        if ($input->getArgument('script_name')) {
            $dumpOptions['script_name'] = $input->getArgument('script_name');
        }
        if ($input->getOption('base-uri')) {
            $dumpOptions['base_uri'] = $input->getOption('base-uri');
        }

        $dumper = new ApacheMatcherDumper($router->getRouteCollection());

        //Outputs the apache routing into the console
        if ($input->getOption('console-output')) {
            $output->writeln($dumper->dump($dumpOptions), OutputInterface::OUTPUT_RAW);
        } else {
            //Looks for the specified path, default one is the web directory of the legacy app
            if ($this->getContainer()->hasParameter('htaccess_path')) {
                $legacy_web_path = $this->getContainer()->getParameter('htaccess_path');
            } else {
                $legacy_web_path = $this->getContainer()->getParameter('legacy_path') . 'web' . DIRECTORY_SEPARATOR . '.htaccess';
            }

            //Outputs the apache routes into the specified .htacces file
            file_put_contents($legacy_web_path, $dumper->dump($dumpOptions));
            $output->writeln('Reloaded routes into ' . $legacy_web_path);
        }
    }
}
