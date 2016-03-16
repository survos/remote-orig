<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\SqsCommand;
use AppBundle\Exception\AssignmentExceptionInterface;
use AppBundle\Exception\AssignmentNotFound;
use AppBundle\Exception\LatLonNotFound;
use AppBundle\Exception\PosseExceptionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExternalWeatherCommand extends SqsCommand
{
    private $services;

    /** @var string */
    private $fromQueueName;

    /** @var string */
    private $toQueueName;

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('demo:weather')
            ->setDescription('Process a queue dedicated to weather')
            ->setHelp("Reads from an SQS queue, looks up the weather, then pushes back to one")
            ->addArgument(
                'from-queue',
                InputArgument::REQUIRED,
                'From queue name'
            )
            ->addArgument(
                'to-queue',
                InputArgument::OPTIONAL,
                'To queue name',
                false
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @throws \Exception
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->services = [];
        $this->fromQueueName = $input->getArgument('from-queue');
        $this->toQueueName = $input->getArgument('to-queue');
        $this->processQueue($this->fromQueueName);
        return 0; // OK
    }

    protected function processMessage($data, $message)
    {
        $data = (array) $data;
        try {
            if (!isset($data['assignment'])) {
                throw new AssignmentNotFound($data, "Missing assignment in JSON data");
            }
            if ($this->input->getOption('verbose')) {
                dump($data);
            }

            $assignment = $data['assignment'];

            $answers = $this->processAssignment($assignment, $data);
            if ($this->input->getOption('verbose')) {
                dump($answers);
            }

            if ($answers) {
                $id = $assignment['id'];
                if ($this->output->isVerbose()) {
                    $this->output->writeln("Updating $id");
                    dump($answers);
                }
                $commandMessage = [
                    'command'   => 'appendAnswers',
                    'arguments' => [
                        'assignment_id' => $id,
                        'answers'       => $answers,
                    ],
                ];
                if ($this->toQueueName) {
                    $this->queue($this->toQueueName, $commandMessage);
                    $this->output->writeln("Deleting $id");
                } else {
                    dump($commandMessage);
                    $this->output->writeln("No output queue specified");
                }
                //all good, remove from the queue
                $this->deleteMessage($this->fromQueueName, $message);
            }
        } catch (\Exception $e) {
            if ($e instanceof AssignmentExceptionInterface) {
                $this->output->writeln(
                    "Assignment #{$e->getAssignmentId()}. ".$e->getMessage()." data:".json_encode(
                        $e->getRelatedData()
                    )
                );
                // handled exception, remove from queue
                $this->deleteMessage($this->fromQueueName, $message);
            } elseif ($e instanceof PosseExceptionInterface) {
                $this->output->writeln($e->getMessage()." data:".json_encode($e->getRelatedData()));
                // handled exception, remove from queue
                $this->deleteMessage($this->fromQueueName, $message);
            } else {
                // needs sorting as it shouldn't happen
                $this->output->writeln($e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * get weather data - store locally to not fetch in case
     */
    private function getWeatherData($lat, $lon)
    {
        if (isset($this->services['weather'])) {
            $serviceData = $this->services['weather'];
        } else {

            $serviceData = json_decode(
                file_get_contents(
                    "http://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid=0dde8683a8619233195ca7917465b29d"
                ),
                true
            );
            $this->services['weather'] = $serviceData;
        }

        return $this->services['weather'];
    }

    /**
     * @param array $assignment
     * @param array $data
     * @return array
     */
    private function processAssignment($assignment, $data)
    {
        $answers = [];
        $lat = isset($assignment['latitude']) ? floatval($assignment['latitude']) : false;
        $lon = isset($assignment['longitude']) ? floatval($assignment['longitude']) : false;

        if (!$lat || !$lon) {
            throw new LatLonNotFound($assignment['id'], $assignment, "Latitude or Longitude not found");
        }

        foreach ($data['questions'] as $question) {
            if (!isset($question['code'])) {
                continue;
            }
            $weatherData = $this->getWeatherData($lat, $lon);
            switch ($code = $question['code']) {
                case 'temp':
                case 'temperature':
                    // needs persisting to responses
                    $answers[$code] = $weatherData['main']['temp'];
                    break;
                case 'wind_speed':
                    $answers[$code] = $weatherData['wind']['speed'];
                    break;
                default:
                    //throw new \Exception("Unhandled field $code in survey");
            }
        }

        return $answers;
    }
}
