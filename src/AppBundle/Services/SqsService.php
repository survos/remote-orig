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
        return $this->awsSqs->getQueueAttributes(
            [
                'QueueUrl'       => $this->getQueueUrl($queueName),
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
        return $this->awsSqs->purgeQueue(
            [
                'QueueUrl' => $this->getQueueUrl($queueName),
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
        $messages = $this->receiveMessages($queueName)->toArray();
        if (!isset($messages['Messages']) || !is_array($messages['Messages'])) {
            return;
        }

        foreach ($messages['Messages'] as $message) {
            $this->getAwsSqs()->deleteMessage(
                [
                    'QueueUrl'      => $this->getQueueUrl($queueName),
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
        $this->getAwsSqs()->deleteMessage(
            [
                'QueueUrl'      => $this->getQueueUrl($queueName),
                'ReceiptHandle' => $receiptHandle,
            ]
        );
    }

    /**
     * @param string $queueName
     * @param array $options
     * @return string queue URL
     */
    public function createQueue($queueName, $options = [])
    {
        $options['QueueName'] = $queueName;
        $result = $this->awsSqs->createQueue($options);
        return $result->get('QueueUrl');
    }

    /**
     * @param string $queueName
     * @param object|array $messageData
     * @return array
     */
    public function queue($queueName, $messageData)
    {
        $sqsResponse = $this->awsSqs->sendMessage(
            [
                'QueueUrl'    => $this->getQueueUrl($queueName),
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
        return preg_match('{^https?:}', $queueName) ? $queueName :
            sprintf('https://sqs.us-east-1.amazonaws.com/%s/%s', $this->awsAccountId, $queueName);
    }

    /**
     * @param string $queueName
     * @param array $options
     * @return \Aws\Result
     */
    public function receiveMessages($queueName, array $options = [])
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(
            [
                'MaxNumberOfMessages' => 10,
                'VisibilityTimeout'   => 3, // was 20
                'WaitTimeSeconds'     => 2, // was 20
            ]
        );
        $options = $resolver->resolve($options);
        $options['QueueUrl'] = $this->getQueueUrl($queueName);
        try {
            return $this->awsSqs->receiveMessage($options);
        } catch (\Exception $e) { // TODO: should probably be CurlException!
            print $e->getMessage();
            die("\nCurl Exception.  Dying so it can be restarted.\n");
        }
    }

    /**
     * @return string
     */
    public function getArnPrefix()
    {
        return 'arn:aws:sqs:' . $this->awsSqs->getRegion() . ':' . $this->awsAccountId  . ':';
    }

    /**
     * @param string $queueName
     * @return \Aws\Result
     */
    public function setTurkQueuePermissions($queueName) {
        $queueUrl = $this->getQueueUrl($queueName);
        $queueName = preg_replace('{^.*/}', '', $queueName); // in case it's really a URL
        // Adapted from http://docs.aws.amazon.com/AWSMechTurk/latest/AWSMturkAPI/ApiReference_NotificationReceptorAPI_SQSTransportArticle.html#ApiReference_NotificationReceptorAPI_SQSTransportArticle-policy-document
        $policy = [
            'Version' => '2008-10-17',
            'Id' => $this->getArnPrefix() . $queueName . '/MTurkOnlyPolicy',
            'Statement' => [
                [
                    'Sid' => 'MTurkOnlyPolicy',
                    'Effect' => 'Allow',
                    'Principal' => [
                        'AWS' => 'arn:aws:iam::755651556756:user/MTurk-SQS'
                    ],
                    'Action' => 'SQS:SendMessage',
                    'Resource' => $this->getArnPrefix() . $queueName,
                ]
            ]
        ];
        return $this->awsSqs->setQueueAttributes([
            'QueueUrl' => $queueUrl,
            'Attributes' => [
                'Policy' => json_encode($policy),
            ],
        ]);
    }

    /**
     * @param string $queueName
     * @return string
     */
    public function addDeadLetterQueue($queueName)
    {
        $maxReceiveCount = 6;
        $queueName = preg_replace('{^.*/}', '', $queueName); // in case it's really a URL
        $deadQueueName = $queueName . '-dead';
        $deadQueueUrl = $this->createQueue($deadQueueName);
        $redrivePolicy = [
            'maxReceiveCount' => $maxReceiveCount,
            'deadLetterTargetArn' => $this->getArnPrefix() . $deadQueueName,
        ];
        $this->awsSqs->setQueueAttributes([
            'QueueUrl' => $this->getQueueUrl($queueName),
            'Attributes' => [
                'RedrivePolicy' => json_encode($redrivePolicy),
            ],
        ]);
        return $deadQueueUrl;
    }
}