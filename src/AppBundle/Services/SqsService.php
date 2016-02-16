<?php
/**
 * Interact with Amazon's SQS queues
 */

namespace AppBundle\Services;

use Aws\Credentials\Credentials;
use Aws\Sqs\SqsClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SqsService
{
    /** @var array */
    public static $instances = [];

    /** @var SqsClient */
    private $awsSqs;

    /** @var string */
    private $awsAccountId;

    /**
     * @param string    $awsAccountId
     * @param SqsClient $awsSqs
     */
    public function __construct($awsAccountId, SqsClient $awsSqs)
    {
        $this->awsAccountId = $awsAccountId;
        $this->awsSqs = $awsSqs;
        self::$instances[$awsAccountId] = $this;
    }


    /**
     * a bit hacky
     *
     * @param string $accountId
     * @param string|null $key
     * @param string|null $secret
     * @return SqsService
     */
    public function getForCredentials($accountId, $key=null, $secret=null)
    {
        // extract credentials if in one string
        if (is_null($key) && is_null($secret) && strpos($accountId,':') !== false){
            $credentials = explode(':',$accountId);
            if (count($credentials) == 3) {
                $accountId = array_shift($credentials);
                $key = array_shift($credentials);
                $secret = array_shift($credentials);
            }
        }
        if (isset(self::$instances[$accountId])) {
            return self::$instances[$accountId];
        }
        $awsCredentials = new Credentials($key, $secret);

        $awsSqs = new SqsClient(
            [
                'credentials' => $awsCredentials,
                'region'      => 'us-east-1',
                'version'     => '2012-11-05',
            ]
        );

        $instance = new self(
            $accountId,
            $awsSqs
        );

        return $instance;
    }

    /**
     * @return SqsClient
     */
    public function getAwsSqs()
    {
        return $this->awsSqs;
    }

    /**
     * @return array
     */
    public function listAllQueues($withAttributes = false)
    {
        $queues = $this->getAwsSqs()->listQueues()->toArray();
        if (isset($queues['QueueUrls'])) {
            if (!$withAttributes) {
                return array_combine($queues['QueueUrls'], $queues['QueueUrls']);
            } else {
                $result = [];
                foreach ($queues['QueueUrls'] as $queue) {
                    $attributes = $this->getQueueAttributes($queue)->toArray();
                    $result[$queue] = $attributes['Attributes'];
                }

                return $result;
            }
        }

        return [];
    }

    /**
     * @param string $queueName
     * @return \Aws\Result
     */
    public function getQueueAttributes($queueName)
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        } else {
            $queueUrl = $this->getQueueUrl($queueName);
        }

        return $this->awsSqs->getQueueAttributes(
            [
                'QueueUrl'       => $queueUrl,
                'AttributeNames' => ['ApproximateNumberOfMessages'],
            ]
        );
    }

    /**
     * purge queue, is run asynchronously, can take up to 60s
     *
     * @param string $queueName
     * @return \Aws\Result
     */
    public function purgeQueue($queueName)
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        } else {
            $queueUrl = $this->getQueueUrl($queueName);
        }

        /** @var Model $sqsResponse */

        return $this->awsSqs->purgeQueue(
            [
                'QueueUrl' => $queueUrl,
            ]
        );
    }

    /**
     * that removes all messages immediately
     * purge can take up to 60s, can't be run only once per minute
     *
     * @param string $queueName
     */
    public function removeAllMessages($queueName)
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        } else {
            $queueUrl = $this->getQueueUrl($queueName);
        }

        $messages = $this->receiveMessages($queueName)->toArray();
        if (!isset($messages['Messages']) || !is_array($messages['Messages'])) {
            return;
        }

        foreach ($messages['Messages'] as $message) {
            $this->getAwsSqs()->deleteMessage(
                [
                    'QueueUrl'      => $queueUrl,
                    'ReceiptHandle' => $message['ReceiptHandle'],
                ]
            );
        }
    }

    /**
     * that message immediately
     *
     * @param string $queueName
     */
    public function removeMessage($queueName, $receiptHandle)
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        } else {
            $queueUrl = $this->getQueueUrl($queueName);
        }

        $this->getAwsSqs()->deleteMessage(
            [
                'QueueUrl'      => $queueUrl,
                'ReceiptHandle' => $receiptHandle,
            ]
        );
    }

    /**
     * @param string $queueName
     * @param object|array $messageData
     * @return array
     */
    public function queue($queueName, $messageData)
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        } else {
            $queueUrl = $this->getQueueUrl($queueName);
        }
        /** @var Model $sqsResponse */
        $sqsResponse = $this->awsSqs->sendMessage(
            [
                'QueueUrl'    => $queueUrl,
                'MessageBody' => json_encode($messageData),
            ]
        );
        if ($sqsResponse->hasKey('MessageId')) {
            $data = [
                'status'  => 'success',
                'message' => 'Message queued',
                'id'      => $sqsResponse->get('MessageId'),
            ];
        } else {
            $data = [
                'status'  => 'error',
                'message' => 'Failed to queue message',
            ];
        }

        return $data;
    }

    /**
     * @param string  $queueName
     * @param Request $request
     * @param string  $username
     * @param string  $processingUrl
     * @return array
     */
    public function queueRequest($queueName, Request $request, $username = '', $processingUrl = '')
    {
        $messageData = $this->getRequestData($request);
        $messageData->ip_address = $request->getClientIp();
        $messageData->received_time = time(); // ?
        $messageData->queuing_url = $request->getUri();
        $urlParameters = $request->query->all();
        if ($urlParameters) {
            $messageData->url_parameters = $urlParameters;
        }
        if ($processingUrl) {
            $messageData->processing_url = $processingUrl;
        }
        if ($username) {
            $messageData->username = $username;
        }

        return $this->queue($queueName, $messageData);
    }

    /**
     * @param  Request $request
     * @return object
     */
    private function getRequestData(Request $request)
    {
        if ($request->getMethod() == 'GET') {
            return (object)$request->query->all();
        }
        if (preg_match('{^application/x-www-form-urlencoded}', $request->headers->get('content-type'))) {
            return (object)$request->request->all();
        }
        $content = $request->getContent();

        return json_decode(empty($content) ? '{}' : $content);
    }

    /**
     * @param string $queueName
     * @return string
     */
    public function getQueueUrl($queueName)
    {
        // likely this should be part of the credentials / constructor
        return sprintf('https://sqs.us-east-1.amazonaws.com/%s/%s', $this->awsAccountId, $queueName);
    }

    /**
     * @param string $queueName
     * @param array $options
     * @return \Aws\Result
     */
    public function receiveMessages($queueName, array $options = [])
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        } else {
            $queueUrl = $this->getQueueUrl($queueName);
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults(
            [
                'MaxNumberOfMessages' => 10,
                'VisibilityTimeout'   => 3, // was 20
                'WaitTimeSeconds'     => 2, // was 20
            ]
        );
        $options = $resolver->resolve($options);
        $options['QueueUrl'] = $queueUrl;
        try {
            return $this->awsSqs->receiveMessage($options);
        } catch (\Exception $e) { // TODO: should probably be CurlException!
            print $e->getMessage();
            die("\nCurl Exception.  Dying so it can be restarted.\n");
        }
    }
}