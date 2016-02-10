<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\BaseCommand;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
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
                'queue',
                null,
                InputOption::VALUE_REQUIRED,
                'SQS Queue Name'
            );
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->services = [];

        $isVerbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        $projectCode = $input->getOption('project-code');

        /** @type AssignmentResource $assignmentResource */
        $assignmentResource = new AssignmentResource($this->sourceClient);

        $page = 0;
        $perPage = 10;
        $maxPages = 1;
        $criteria = [];
        $data = [];
        // need much better filter!  Maybe the survey or wave needs to associate itself with an external service?
        $assignments = $assignmentResource->getList(
            1,
            1,
            [
                'survey_response_status_code' => 'initiated',
            ],
            null,
            null,
            ['project_code' => 'behattest']
        );

        // if no items, return
        if (!count($assignments['items']) || !$assignments['total']) {
            return;
        }
        $tasksIds = array_map(
            function ($item) {
                return $item['task_id'];
            },
            $assignments['items']
        );
        $taskResource = new TaskResource($this->sourceClient);
        $tasks = $taskResource->getList(null, null, ['id' => array_unique($tasksIds)], ['id' => SurvosCriteria::IN]);
        $surveyByTask = [];
        foreach ($tasks['items'] as $task) {
            $surveyByTask[$task['id']] = isset($task['survey_json']) ? json_decode($task['survey_json'], true) : null;
        }
        foreach ($assignments['items'] as $key => $assignment) {
            $taskId = $assignment['task_id'];
            $survey = $surveyByTask[$taskId];
            // $answers = [];
            foreach ($survey['questions'] as $question) {
                if ($isVerbose) {
                    $output->writeln("Checking question \'{$question['text']}\' ");
                }
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
                        throw new Exception("Unhandled field '{$question['code']}' in survey");
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
