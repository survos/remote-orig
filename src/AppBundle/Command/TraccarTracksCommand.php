<?php

namespace AppBundle\Command;

use AppBundle\Command\Base\SqsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TraccarTracksCommand extends SqsCommand
{
    protected $name = 'traccar:tracks';
    protected $description = 'Import tracks from Traccar queue';
    protected $help = 'Reads from an SQS queue';

    /**
     * @param InputInterface   $input
     * @param OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $continue = true;
        while ($continue) {
            $processed = $this->processQueue(
                $input->getArgument('queue-name')
            );
            $this->output->writeln("$processed messages processed");
            $continue = false;
        }
        return 0; // OK
    }

    protected function processMessage($inData, $message)
    {
        $username = $inData->id;
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', $inData->timestamp);
        $userAgent = $inData->user_agent;
        $app = ['name' => 'Traccar'];
        if (preg_match('{TraccarClient/([\d.]+)}', $userAgent, $m)) {
            $app['version'] = $m[1];
        }
        $device = ['uuid' => md5($username . '-traccar')]; // not sure what to use;
        if (preg_match('{Android ([\d.]+); (\S+) Build}', $userAgent, $m)) {
            $device['platform'] = 'Android';
            $device['version'] = $m[1];
            $device['model'] = $m[2];
        }
        elseif (preg_match('{ Darwin/}', $userAgent)) {
            $device['platform'] = 'iOS';
        }
        $outData = [
            'tz' => 'America/New_York', // get from user
            'app' => $app,
            'device' => $device,
            'location' => [
                'uuid' => md5($username . $timestamp),
                'coords' => [
                    'latitude' => $inData->lat,
                    'longitude' => $inData->lon,
                    'speed' => $inData->speed,
                    'heading' => $inData->bearing,
                    // 'accuracy' => null,
                    'altitude' => $inData->altitude,
                ],
                'battery' => [
                    'level' => $inData->batt / 100,
                ],
                'timestamp' => $timestamp,
            ],
        ];
        print json_encode($outData, JSON_PRETTY_PRINT);
        return false;
    }
}

/*
{
    "tz": "America/New_York",
    "app": {
      "name": "Tracker",
      "version": "1.4.20"
    },
    "device": {
      "uuid": "a85c1413dde47af4",
      "model": "SM-G920P",
      "serial": "0715f7c3ac161e38",
      "cordova": "4.1.1",
      "version": "5.1.1",
      "platform": "Android",
      "available": true,
      "isVirtual": false,
      "manufacturer": "samsung"
    },
    "location": [
      {
        "uuid": "88001375-f589-4be8-81f0-06df720a73c7",
        "coords": {
          "speed": 0,
          "heading": 0,
          "accuracy": 20.464000701904,
          "altitude": 0,
          "latitude": 38.9135748,
          "longitude": -77.0448877
        },
        "extras": {
          "mode": "background",
          "event": {
            "code": "heartbeat",
            "memory": {
              "capacity": 2812780544,
              "availableCapacity": 415010816
            }
          }
        },
        "battery": {
          "level": 0.6700000166893,
          "is_charging": false
        },
        "activity": {
          "type": "still",
          "confidence": 77
        },
        "odometer": 3623.9616699219,
        "is_moving": false,
        "timestamp": "2016-03-18T17:17:53.676Z"
      }
    ]
}

 */
