<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\SqsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class ProcessTracksCommand extends SqsCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('process:tracks')
            ->setDescription('Process a queue dedicated to tracks')
            ->setHelp("Reads from an SQS queue")
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit messages read from the queue in one go',
                3
            );
    }

    protected function processMessage($data)
    {
        var_dump($data);
    }

    /**
     * @param InputInterface   $input
     * @param OutputInterface  $output
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->processQueue(
            $input->getArgument('queue-name'),
            $input->getOption('limit')
        );
        return true;
    }
}
