<?php

namespace AppBundle\Command\Base;

use AppBundle\Services\SqsService;
use Survos\Client\SurvosClient;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class BaseCommand extends ContainerAwareCommand
{
    protected $parameters;
    /**
     * @type SurvosClient
     */
    protected $client;
    /**
     * @type SurvosClient
     */
    protected $sourceClient;

    /* @type SqsService */
    protected $sqs;
    protected $awsKey;
    protected $awsSecret;
    protected $awsAccountId;

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('demo:base')
            ->setDescription('Base, should always be overwritten.  Should probably be declared differently');
        $this->configureCommand();
    }

    // override to use in eg traits
    public function configureCommand()
    {

    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->parameters = $this->getContainer()->getParameter('survos');
        if (!is_array($this->parameters) || !count($this->parameters)) {
            $output->writeln('<error>Config file could not be found or is not correct</error>');
            die();
        }

        if ($input->hasOption('queue-name')) {
        // get sqs service - set up with credentials from cli if passed
        $this->sqs = $this->getContainer()->get('survos.sqs');

        if ($input->hasOption('aws-key') && $input->getOption('aws-key')) {
            $this->awsKey = $input->getOption('aws-key');
            $this->awsSecret = $input->getOption('aws-secret');
            $this->awsAccountId = $input->getOption('aws-account-id');
            $this->sqs = $this->sqs->getForCredentials(
                $input->getOption('aws-account-id'),
                $input->getOption('aws-key'),
                $input->getOption('aws-secret')
            );
        }
        }

        // configure target client
        $this->client = new SurvosClient($this->parameters['target']['endpoint']);
        $authResult = false;

        if ($input->hasOption('access_token') && $input->getOption('access_token')) {
            $authResult = $this->client->authorize($input->getOption('access_token'));
        } else {
            $authResult = $this->client->authorize(
                $this->parameters['target']['username'],
                $this->parameters['target']['password'],
                $this->parameters['target']['client_id'],
                $this->parameters['target']['client_secret']
            );
        }

        if (!$authResult) {
            $output->writeln(
                "<error>Wrong credentials for target endpoint: {$this->parameters['target']['endpoint']}</error>"
            );
        }

        // configure source client (optional)
        $this->sourceClient = null;

        if ($this->parameters['source']) {
            $this->sourceClient = new SurvosClient($this->parameters['source']['endpoint']);

            if (!$this->sourceClient->authorize(
                $this->parameters['source']['username'],
                $this->parameters['source']['password'],
                $this->parameters['source']['client_id'],
                $this->parameters['source']['client_secret']
            )
            ) {
                $output->writeln(
                    "<error>Wrong credentials for source endpoint: {$this->parameters['source']['endpoint']}</error>"
                );
                die();
            }
        }

    }

    protected function printTableResponse(array $data, OutputInterface $output)
    {
        $table = new Table($output);

        $columns = [];
        foreach ($data as $line) {
            $this->processRow($line);
            $columns = array_unique(array_merge($columns, array_keys($line)));
        }
        // make sure all rows have the same columns
        $output = [];
        foreach ($data as $line) {
            $row = [];
            foreach ($columns as $column) {
                $row[$column] = isset($line[$column]) ? $line[$column] : '';
            }

            $output[] = $row;
        }
        $table
            ->setHeaders($columns)
            ->addRows($output)
            ->render();
    }

    protected function printJsonResponse(array $data, OutputInterface $output)
    {
        $output->write(json_encode($data));
    }

    /**
     * @param string                                            $format
     * @param array                                             $data
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function printResponse($format = 'table', array $data, OutputInterface $output)
    {
        $method = "print".ucfirst($format)."Response";
        $this->$method($data, $output);
    }

    protected function processRow(&$data)
    {

    }

}
