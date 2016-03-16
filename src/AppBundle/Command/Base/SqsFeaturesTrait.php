<?php
/**
 * Created by PhpStorm.
 * User: pg
 * Date: 16/02/2016
 * Time: 09:46
 */

namespace AppBundle\Command\Base;


use Symfony\Component\Console\Input\InputOption;

trait SqsFeaturesTrait
{
    public function configureCommand()
    {
        $this->addOption(
                'queue-name',
                null,
                InputOption::VALUE_REQUIRED,
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
     * @param string $queueName
     * @return string
     */
    protected function getQueueUrl($queueName)
    {
        return preg_match('{^https?:}', $queueName) ? $queueName :
            $this->sqs->getQueueUrl(['QueueName' => $queueName])->get('QueueUrl');
    }
}
