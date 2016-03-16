<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\BaseCommand;
use AppBundle\Command\Base\SqsFeaturesTrait;
use Aws\Result;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class ProcessTracksCommand extends BaseCommand // BaseCommand
{
    // add sqs parameters - we can use only one trait at once for now
    use SqsFeaturesTrait;

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
                InputOption::VALUE_OPTIONAL,
                'Limit messages read from the queue in one go',
                3
            );
    }

    /**
     * @param InputInterface   $input
     * @param OutputInterface  $output
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $options = [
            'QueueUrl' => $this->getQueueUrl($input->getOption('queue-name')),
            'MaxNumberOfMessages' => $input->getOption('limit'),
        ];

        /** @type Result $result */
        $result = $this->sqs->receiveMessage($options);
        // iterate and query each sqs queue to get messages
        if (isset($result['Messages'])) {
            foreach ($result['Messages'] as $message) {
                $data = json_decode($message['Body'], true);
                var_dump($data);
            }
        }
        return true;
    }
}
