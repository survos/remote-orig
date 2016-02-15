<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\BaseCommand;
use Aws\Result;
use Survos\Client\Resource\AssignmentResource;
use Survos\Client\Resource\ProjectResource;
use Survos\Client\Resource\TaskResource;
use Survos\Client\Resource\WaveResource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class ProcessTracksCommand extends BaseCommand // BaseCommand
{
    private $services;

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('process:tracks')
            ->setDescription('Process a queue dedicated to tracks')
            ->setHelp("Reads from an SQS queue")
            ->addOption(
                'queue-url',
                null,
                InputOption::VALUE_REQUIRED,
                'SQS Queue Url'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'SQS Queue Url',
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

        $queueUrl = $input->getOption('queue-url');
        $limit = $input->getOption('limit');

        $options = [
            'MaxNumberOfMessages' => $limit,
        ];

        /** @type Result $messages */
        $messages = $this->sqs->receiveMessages($queueUrl, $options)->toArray();
var_dump($messages);
        // iterate and query each sqs queue to get messages
        foreach ($messages['Messages'] as $message) {
            $data = json_decode($message['Body'], true);
            var_dump($data);
            // process track
        }

        die();


    }


}
