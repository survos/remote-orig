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

    private $services;

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
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->services = [];

        $queueUrl = $input->getOption('queue-name');

        $limit = $input->getOption('limit');

        $options = [
            'MaxNumberOfMessages' => $limit,
        ];

        /** @type Result $messages */
        $messages = $this->sqs->receiveMessages($queueUrl, $options)->toArray();
        // iterate and query each sqs queue to get messages
        if (isset($messages['Messages'])) {
            foreach ($messages['Messages'] as $message) {
                $data = json_decode($message['Body'], true);
                var_dump($data);
                // process track

            }
        }

    }


}
