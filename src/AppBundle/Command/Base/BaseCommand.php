<?php

namespace AppBundle\Command\Base;

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

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('demo:base')
            ->setDescription('Base, should always be overwritten.  Should probably be declared differently')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {

        $this->parameters = $this->getContainer()->getParameter('survos');
        if (!is_array($this->parameters) || !count($this->parameters)) {
            $output->writeln('<error>Config file could not be found or is not correct</error>');
            die();
        }

        // configure target client
        $this->client = new SurvosClient($this->parameters['target']['endpoint']);

        if (!$this->client->authorize(
            $this->parameters['target']['username'],
            $this->parameters['target']['password']
        )
        ) {
            $output->writeln(
                "<error>Wrong credentials for target endpoint: {$this->parameters['target']['endpoint']}</error>"
            );
            // die();
        }

        // configure source client (optional)
        $this->sourceClient = null;

        if ($this->parameters['source']) {
            $this->sourceClient = new SurvosClient($this->parameters['source']['endpoint']);

            if (!$this->sourceClient->authorize(
                $this->parameters['source']['username'],
                $this->parameters['source']['password']
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
