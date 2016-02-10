<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\BaseCommand;
use Aws\Result;
use Survos\Client\Resource\AssignmentResource;
use Survos\Client\Resource\TaskResource;
use Survos\Client\Resource\WaveResource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class ExternalWeatherCommand extends BaseCommand // BaseCommand
{
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
        $this->assignmentResource = new AssignmentResource($this->sourceClient);

        $isVerbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        $projectCode = $input->getOption('project-code');

        $waveResource = new WaveResource($this->sourceClient);

        // get list of external waves
        $waves = $waveResource->getList(null, null, null, null, null, ['project_code' => $projectCode]);

        foreach ($waves['items'] as $wave) {
            $queueName = $wave['external_queue_name'];
            /** @type Result $messages */
            $messages = $this->sqs->receiveMessages($queueName);
            // iterate and query each sqs queue to get messages
            foreach ($messages->toArray() as $message) {
                $message = $message[0];
                $data = json_decode($message['Body'], true);
                //query messages to get assignments for processing
                $assignment = $this->assignmentResource->getOneBy(['id' => $data['assignment']['Id']]);
                $this->processAssignment($assignment);
            }
            //
        }


        die();


    }

    function saveAssignment($client, $data)
    {
        $resource = new \Survos\Client\Resource\AssignmentResource($client);
        $response = $resource->save($data);
    }

    /**
     * get weather data - store locally to not fetch in case
     */
    private function getWeatherData()
    {
        if (isset($this->services['weather'])) {
            $serviceData = $this->services['weather'];
        } else {

            $serviceData = json_decode(
                file_get_contents(
                    "http://api.openweathermap.org/data/2.5/weather?lat=35&lon=139&appid=0dde8683a8619233195ca7917465b29d"
                ),
                true
            );
            $this->services['weather'] = $serviceData;
        }

        return $this->services['weather'];

    }

    private function processAssignment($assignment)
    {

        /** @type AssignmentResource $assignmentResource */
        $assignmentResource = $this->assignmentResource;

        $tasksId = $assignment['task_id'];

        /** @type TaskResource $taskResource */
        $taskResource = new TaskResource($this->sourceClient);
        $task = $taskResource->getOneBy(['id' => $tasksId]);


        $taskId = $assignment['task_id'];
        $survey = isset($task['survey_json']) ? json_decode($task['survey_json'], true) : null;

        // $answers = [];
        foreach ($survey['questions'] as $question) {
//            if ($isVerbose) {
//                $output->writeln("Checking question \'{$question['text']}\' ");
//            }
            switch ($question['code']) {
                case 'temp':
                    $weatherData = $this->getWeatherData();
                    // needs persisting to responses
                    $answers[$question['code']] = $weatherData['main']['temp'];
                    break;
                case 'wind_speed':
                    $weatherData = $this->getWeatherData();
                    $answers[$question['code']] = $weatherData['wind']['speed'];
                    break;
                default:
                    throw new \Exception("Unhandled field '{$question['code']}' in survey");
            }

        }
        if (!empty($answers)) {
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
