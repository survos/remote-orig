<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\BaseCommand;
use AppBundle\Command\Base\SqsFeaturesTrait;
use Aws\Result;
use Survos\Client\Resource\AssignmentResource;
use Survos\Client\Resource\TaskResource;
use Survos\Client\Resource\WaveResource;
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
            ->addOption(
                'project-code',
                null,
                InputOption::VALUE_REQUIRED,
                'Project code'
            );
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->services = [];

        $queueName = $input->getOption('queue-name');
        /** @type Result $messages */
        $messages = $this->sqs->receiveMessages($queueName)->toArray();
        if (!isset($messages['Messages'])) {
            $output->writeln('No messages in queue');
            exit();
        }
        // iterate and query each sqs queue to get messages
        foreach ($messages['Messages'] as $message) {
            $data = json_decode($message['Body'], true);           //query messages to get assignments for processing
            if (!isset($data['assignment'])) {
                $this->sqs->removeMessage($queueName, $message['ReceiptHandle']);
                continue;
            }

            $this->processAssignment($data['assignment'], $data);
            $this->sqs->removeMessage($queueName, $message['ReceiptHandle']);
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

    private function processAssignment($assignment, $data)
    {


        $lat = isset($assignment['Latitude']) ? floatval($assignment['Latitude']) : false;
        $lon = isset($assignment['Longitude']) ? floatval($assignment['Longitude']) : false;
        if (!$lat || !$lon) {
            return;
        }
        // $answers = [];
        foreach ($data['questions'] as $question) {
            if (!isset($question['code'])) {
                continue;
            }
//            if ($isVerbose) {
//                $output->writeln("Checking question \'{$question['text']}\' ");
//            }
            switch ($question['code']) {
                case 'temp':
                    $weatherData = $this->getWeatherData($lat, $lon);
                    // needs persisting to responses
                    $answers[$question['code']] = $weatherData['main']['temp'];
                    break;
                case 'wind_speed':
                    $weatherData = $this->getWeatherData($lat, $lon);
                    $answers[$question['code']] = $weatherData['wind']['speed'];
                    break;
//                default:
//                    throw new \Exception("Unhandled field '{$question['code']}' in survey");
            }

        }
        if (!empty($answers)) {
            /** @type AssignmentResource $assignmentResource */
            $assignmentResource = new AssignmentResource($this->sourceClient);

            $assignment = $assignmentResource->getOneBy(['id' => $assignment['Id']]);
            $assignment['flat_data'] = array_merge(
                $assignment['flat_data'] ?: [],
                $answers
            );
            $assignmentResource->save($assignment);
        }


    }


    /**
     * @param $member
     * @return array
     */
    private function getMemberCriteria()
    {
        return [
            'enrollment_status_code' => 'applicant',
        ];
    }

}
