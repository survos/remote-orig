<?php

namespace AppBundle\Command\Base;

use Aws\Credentials\Credentials;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class SqsCommand extends BaseCommand
{
    /* @type SqsClient */
    protected $sqs;

    protected function configure()
    {
        parent::configure();
        $this->setName('demo:sqs')
            ->setDescription('Should always be overridden')
            ->addArgument(
                'queue-name',
                InputArgument::REQUIRED,
                'SQS Queue Name'
            )
            ->addOption(
                'aws-key',
                null,
                InputOption::VALUE_REQUIRED,
                'SQS key (defaults to aws_key from parameters.yml)'
            )
            ->addOption(
                'aws-secret',
                null,
                InputOption::VALUE_REQUIRED,
                'SQS secret (defaults to aws_secret from parameters.yml)'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $container = $this->getContainer();
        $credentials = new Credentials(
            $input->getOption('aws-key') ?: $container->getParameter('aws_key'),
            $input->getOption('aws-secret') ?: $container->getParameter('aws_secret')
        );
        $region = $input->hasOption('aws-region') ? $input->getOption('aws-region') : 'us-east-1';
        $this->sqs = new SqsClient(
            [
                'credentials' => $credentials,
                'region'      => $region,
                'version'     => '2012-11-05',
            ]
        );
    }

    /**
     * @param string $queueName
     * @return string
     */
    protected function getQueueUrl($queueName)
    {
        return preg_match('{^https?:}', $queueName) ? $queueName :
            $this->sqs->getQueueUrl(['QueueName' => $queueName])->get('QueueUrl');
    }

    protected function processQueue($queueName, $limit = 10)
    {
        $options = [
            'QueueUrl' => $this->getQueueUrl($queueName),
            'MaxNumberOfMessages' => $limit,
        ];
        /** @type Result $result */
        $result = $this->sqs->receiveMessage($options);
        // iterate and query each sqs queue to get messages
        if (isset($result['Messages'])) {
            foreach ($result['Messages'] as $message) {
                $this->processMessage(json_decode($message['Body']));
            }
        }
    }

    /**
     * @param object $data
     */
    protected function processMessage($data)
    {
        throw new \Exception('Override processMessage()');
    }
}