<?php
namespace AppBundle\Command;

use AppBundle\Command\Base\SqsCommand;
use Survos\Client\Resource\ObserveResource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GoogleStaypointsImportCommand extends SqsCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('app:import-staypoints')
            ->setDescription('Google Timeline "My Places" Import')
        ;
    }

    private $queue = [];

    protected function processMessage(array $data, array $message) : bool
    {
        $data = $this->validateMessage($data);
        $payload = (array)$data['payload'];
        if ($this->input->getOption('verbose')) {
            dump($data, $payload);
        }

        $this->survosClient = $this->getClient($data['apiUrl'], $data['accessToken']);

        $localPath = $this->downloadFile($data['parameters']['imageUrl']);
        $answersResolver = new OptionsResolver();
        $answersResolver->setDefaults($payload);
        try {
            $answers = $answersResolver->resolve($this->processFile($localPath));
        } catch (\Throwable $e) {
            $errorMessage = 'Uploaded file is invalid';
            $this->sendError($data['channelCode'], $errorMessage, $data['taskId'], $data['assignmentId']);
            $this->output->writeln('unable to process file: '. $e->getMessage());
            return true;
        }
        if ($this->input->getOption('verbose')) {
            dump($answers);
        }

        $this->sendData($data['channelCode'], $answers, $data['taskId'], $data['assignmentId']);

        return true; // use --delete-bad to leave the message in the queue.
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

    // flatten key/value pairs
    private function flattenArray($a, $prefix=''): array
    {
        $result = [];
        foreach ($a as $key=>$value) {
            if (is_object($value)) {
                $value = (array)$value;
            }
            if (is_array($value))
            {
                $result = array_merge($result, $this->flattenArray($value, $key . '_'));
            } else {
                $result[str_replace(' ', '', $prefix . $key)] = $value;
            }
        }
        return $result;
    }

    protected function processFile($filename)
    {
        if (!file_exists($filename)) {
            throw new \Exception("File '{$filename}' not found");
        }
        $zippedFn = "zip://{$filename}#Takeout/Maps (your places)/Saved Places.json";
        $items = json_decode(file_get_contents($zippedFn)); //
        $count = 0;
        $staypoints = [];
        foreach ($items->features as $item) {
            $it = $this->flattenArray($item->properties);
            if (null !== $data = $this->normalizeItem($it)) { // where does member id come in?  $userId)) {
                $staypoints[] = $data;
                $count++;
            }
        }

        // these are the $answers, and need to correspond to the survey questions.
        return [
            'my_places_count' => $count,
            'google_staypoint' => $staypoints,
        ];
    }

    /*
     * Uncomment for local testing
     * `sf app:import-timeline <queue_name> --api-login <login> --api-pass <pass>`
    */
    protected function XXexecute(InputInterface $input, OutputInterface $output)
    {
        $data = json_decode('{"url": "http://gis.l.survos.com/uploads/tac-google-places.zip", 
             "receiveEndpoint" : "http://gis.l.survos.com/channel/receive/images",
              "apiUrl" : "http://gis.l.survos.com/api1.0/",
               "accessToken" : "4488105c9f8afd5a412f4e9d3fd265be", 
            "records_count": null}', true);

        $this->survosClient = $this->getClient($data['apiUrl'], $data['accessToken']);
        $this->staypointChannelEndpoint = $data['receiveEndpoint'];

        $localPath = $this->downloadFile($data['url']);
        $answersResolver = new OptionsResolver();
        $answersResolver->setDefaults($data);
        $answers = $answersResolver->resolve($this->processFile($localPath));
    }

    /**
     * @param array $item
     * @return array
     */
    private function normalizeItem(array $item) : array
    {
        return [
            'latitude' => $item['Location_Latitude'] ?? $item['GeoCoordinates_Latitude'],
            'longitude' => $item['Location_Longitude'] ?? $item['GeoCoordinates_Longitude'],
            'name' => $item['Title'],
            'google_maps_url' => $item['GoogleMapsURL']
        ];
    }


    private function flushQueue()
    {
        if (empty($this->queue)) {
            return;
        }

        $observeRes = new ObserveResource($this->survosClient);
        foreach ($this->queue as $data) {
        }

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

}
