<?php
namespace AppBundle\Command;

use AppBundle\Command\Base\SqsCommand;
use Bcn\Component\Json\Reader;
use Survos\Client\Resource\ObserveResource;
use Survos\Client\SurvosClient;
use Survos\Client\SurvosException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GoogleTimelineImportCommand extends SqsCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('app:import-timeline')
            ->setDescription('Google Timeline JSON Import')
            ->addOption('api-url', null, InputOption::VALUE_REQUIRED, 'MapMob Api to upload', 'https://api.mapmob.com/api/')
            ->addOption('row-limit', null, InputOption::VALUE_OPTIONAL, 'Number of lines to read from Timeline.json')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'How many points submit at once', 1000)
        ;
    }

    private $queue = [];

    /** @var SurvosClient */
    private $mapmobClient;
    /** @var SurvosClient */
    private $survosClient;

    protected function processMessage($data, $message)
    {
        $data = $this->validateMessage($data);
        $payload = (array)$data['payload'];
        if ($this->input->getOption('verbose')) {
            dump($data, $payload);
        }
        $this->mapmobClient = $this->getClient($this->input->getOption('api-url'), $data['mapmobToken']);
        $this->survosClient = $this->getClient($data['apiUrl'], $data['accessToken']);

        $localPath = $this->downloadFile($payload['timeline_filename']);
        $answersResolver = new OptionsResolver();
        $answersResolver->setDefaults($payload);
        $answers = $answersResolver->resolve($this->processFile($localPath));
        if ($this->input->getOption('verbose')) {
            dump($answers);
        }
        $this->sendAnswers($data['taskId'], $answers);

        return true;
    }

    /**
     * TODO: send the answers back to /api1.0/channel/receive-data
     * @param $taskId
     * @param array $answers
     */
    private function sendAnswers($taskId, array $answers)
    {
        $currentUserId = $this->survosClient->getLoggedUser()['id'];
        $data = [
            'answers' => $answers,
            'memberId' => $currentUserId,
            'taskId' => $taskId,
            'assignmentId' => '',
            'language' => 'en',
        ];
        $observeRes = new ObserveResource($this->survosClient);
        $response = $observeRes->saveResponses($data);
        $this->output->writeln("Submitted, status: {$response['status']}");
    }

    /**
     * @param object $data
     * @return array
     */
    private function validateMessage($data)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined(['action', 'deployment', 'parameters']);
        $resolver->setRequired(['payload', 'mapmobToken', 'apiUrl', 'accessToken', 'taskId']);
        return $resolver->resolve((array) $data);
    }

    private function downloadFile($url)
    {
        $path = sys_get_temp_dir() . '/' . md5($url). '.zip';
        if (!file_exists($path)) {
            $newfname = $path;
            if ($file = fopen($url, 'rb'))
            {
                $newf = fopen($newfname, 'wb');
                if ($newf) {
                    while (!feof($file)) {
                        fwrite($newf, fread($file, 1024 * 8), 1024 * 8);
                    }
                }
                fclose($file);
                if ($newf) {
                    fclose($newf);
                }
            }
        }
        return $path;
    }

    protected function processFile($sourceFile)
    {
        $batchSize = $this->input->getOption('batch-size');
        $limit = $this->input->getOption('row-limit');
        $count = 0;
        $dates = [];
        $userId = $this->mapmobClient->getLoggedUser()['id'];
        foreach ($this->getItems($sourceFile) as $item) {
            if (null !== $data = $this->normalizeItem($item, $userId)) {
                $this->addToQueue($data);
                $date = date('Y-m-d', strtotime($data['timestamp']));
                $dates[$date] = ($dates[$date] ?? 0) + 1;
            }
            $count++;
            if ($count % $batchSize === 0) {
                $this->flushQueue();
            }
            if ($limit && $count >= $limit) {
                $this->output->writeln('Limit reached');
                break;
            }
        }
        $this->flushQueue();
        return [
            'track_count' => array_sum($dates),
            'day_count' => count($dates),
        ];
    }

    /*
     * Uncomment for local testing
     * `sf app:import-timeline <queue_name> --api-login <login> --api-pass <pass>`
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $data = json_decode('{"url": "http://gis.l.survos.com/uploads/gis/123329ae8d85758a8f1a6eb68711a144.zip", "records_count": null}');
        $this->processMessage($data, null);
    }*/

    /**
     * @param $filename
     * @return \Generator
     * @throws \Exception
     */
    private function getItems($filename)
    {
        if (!file_exists($filename)) {
            throw new \Exception("File '{$filename}' not found");
        }
        $fh = fopen("zip://{$filename}#Takeout/Location History/LocationHistory.json", 'r');
        try {
            $reader = new Reader($fh);
            $reader->enter(Reader::TYPE_OBJECT);
            $reader->enter("locations", Reader::TYPE_ARRAY);
            while($product = $reader->read()) {
                yield $product;
            }
            $reader->leave();
            $reader->leave();
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param array $item
     * @param int $userId
     * @return array|null
     */
    private function normalizeItem(array &$item, $userId)
    {
        if (empty($item['timestampMs']) || empty($item['latitudeE7']) || empty($item['longitudeE7'])) {
            return null;
        }
        $e7divider = pow(10, 7);
        return [
            'activity' => $this->getActivity($item) ?? ['type' => 'still', 'confidence' => 100],
            'battery' => ['is_charging' => false, 'level' => 1],
            'uuid' => md5($item['timestampMs'].$item['latitudeE7'].$item['longitudeE7'].$userId),
            'is_moving' => false,
            'timestamp' => date('c', round($item['timestampMs'] / 1000)),
            'coords' => [
                'latitude' => $item['latitudeE7'] / $e7divider,
                'longitude' => $item['longitudeE7'] / $e7divider,
                'accuracy' => $item['accuracy'] ?? 0,
                'speed' => 0,
                'heading' => $item['heading'] ?? 0,
                'altitude' => $item['altitude'] ?? 0,
            ]
        ];
    }

    /**
     * @param array $item
     * @return array|null [type, confidence]
     */
    private function getActivity(array &$item) {
        if (empty($item['activitys'])) {
            return null;
        }
        $confidenceList = [];
        $activities = [];
        foreach ($item['activitys'] as $activity) {
            if (empty($activity['activities'])) {
                continue;
            }
            foreach ($activity['activities'] as $act) {
                if (empty($act['type']) || empty($act['confidence'])) {
                    continue;
                }
                array_push($confidenceList, $act['confidence']);
                array_push($activities, $act);
            }
        }
        if (empty($activities)) {
            return null;
        }
        $max = max($confidenceList);
        $index = array_search($max, $confidenceList);
        return $activities[$index];
    }


    private function flushQueue()
    {
        if (empty($this->queue)) {
            return;
        }
        $deviceId = md5($this->mapmobClient->getLoggedUser()['id']);
        $data = $this->prepareData($this->queue, $deviceId);
        $this->output->writeln(sprintf('Submitting %d points to %s', count($this->queue), $this->mapmobClient->getEndpoint()));
        $this->submitLocationData($this->mapmobClient, $data);
        $this->queue = [];
    }

    private function addToQueue($data)
    {
        $this->queue[] = $data;
    }

    /**
     * @param array $locations
     * @param string $uuid
     * @return array|null
     */
    private function prepareData($locations, $uuid)
    {
        $device = new \stdClass();
        $device->uuid = $uuid;
        $output = ['device' => $device, 'location' => []];
        $output['location'] = $locations;
        return $output;
    }

    protected function initClient()
    {
        //void
    }

    /**
     * @param $apiUrl
     * @param $accessToken
     * @return bool|SurvosClient
     * @throws \Exception
     */
    private function getClient($apiUrl, $accessToken)
    {
        $client = new SurvosClient($apiUrl);
        if (!$client->authByToken($accessToken)) {
            $this->output->writeln(sprintf('Response status: %d', $client->getLastResponseStatus()));
            $this->output->writeln(sprintf('Response data: %s', $client->getLastResponseData()));
            throw new \Exception("Can't log in. ApiUrl: '{$apiUrl}', token: '{$accessToken}'");
        }
        $this->output->writeln(sprintf('Logged in under "%s" against "%s"', $client->getLoggedUser()['username'], $apiUrl));
        return $client;
    }

    /**
     * @param SurvosClient $client
     * @param array $data
     * @throws SurvosException
     */
    private function submitLocationData($client, array $data)
    {
        $observeResource = new ObserveResource($client);
        try {
            $response = $observeResource->postLocation($data);
            $this->output->writeln(sprintf('Response: %s', json_encode($response)));
        } catch (SurvosException $e) {
            $this->output->writeln(sprintf('Response status: %d', $observeResource->getLastResponseStatus()));
            $this->output->writeln(sprintf('Response data: %s', $observeResource->getLastResponseData()));
            $this->output->writeln(sprintf('Error while submitting location data to %s on %s: %s',
                $observeResource->getLastRequestPath(),
                $client->getEndpoint(),
                $e->getMessage()));
            throw $e;
        }
    }
}
