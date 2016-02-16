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
            'queue-url',
            null,
            InputOption::VALUE_REQUIRED,
            'SQS Queue Name'
        )
            ->addOption(
                'aws-account-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'SQS account ID'
            )
            ->addOption(
                'aws-key',
                null,
                InputOption::VALUE_OPTIONAL,
                'SQS key'
            )
            ->addOption(
                'aws-secret',
                null,
                InputOption::VALUE_OPTIONAL,
                'SQS secret'
            );
    }
}