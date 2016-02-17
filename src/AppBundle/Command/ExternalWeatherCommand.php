<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\BaseCommand;
use AppBundle\Command\Base\SqsFeaturesTrait;
use Aws\Result;
use Survos\Client\Resource\AssignmentResource;
use Survos\Client\Resource\TaskResource;
use Survos\Client\Resource\WaveResource;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class ExternalWeatherCommand extends BaseCommand
{
    use SqsFeaturesTrait;
    private $services;

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
                InputArgument::REQUIRED,
                'To queue name'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->services = [];
        $fromQueueName = $input->getArgument('from-queue');
        $toQueueName = $input->getArgument('to-queue');
        /** @type Result $messages */
        $messages = $this->sqs->receiveMessages($fromQueueName);
        if (!isset($messages['Messages'])) {
            $output->writeln('No messages in queue');
            exit();
        }
        // iterate and query each sqs queue to get messages
        foreach ($messages['Messages'] as $message) {
            $data = json_decode($message['Body'], true);
            if (!isset($data['assignment'])) {
                dump($data);
                throw new \Exception("Missing assignment in JSON data");
                $this->sqs->removeMessage($fromQueueName, $message['ReceiptHandle']);
                continue;
            }
            $assignment = $data['assignment'];

            $answers = $this->processAssignment($assignment, $data);

            if ($answers) {
                $id = $assignment['Id'];
                if ($output->isVerbose()) {
                    $output->writeln("Updating $id");
                    dump($answers);
                }
                $commandMessage = [
                    'command' => 'appendAnswers',
                    'arguments' => [
                        'assignmentId' => $id,
                        'answers' => $answers,
                    ]
                ];
                $this->sqs->queue($toQueueName, $commandMessage);
                $output->writeln("Deleting $id");
                // $this->sqs->removeMessage($fromQueueName, $message['ReceiptHandle']);
            }

        }
        return 0; // OK
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
        $lat = isset($assignment['Latitude']) ? floatval($assignment['Latitude']) : false;
        $lon = isset($assignment['Longitude']) ? floatval($assignment['Longitude']) : false;
        if ($lat and $lon) {
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
        }
        return $answers;
    }
}
