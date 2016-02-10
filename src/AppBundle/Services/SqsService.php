<?php
/**
 * Interact with Amazon's SQS queues
 */

namespace AppBundle\Services;

use Aws\Sqs\SqsClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SqsService
{
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
    }

    /**
     * @return SqsClient
     */
    public function getAwsSqs()
    {
        return $this->awsSqs;
    }

    /**
     * @param string $queueName
     * @param object $messageData
     * @return array
     */
    public function queue($queueName, $messageData)
    {
        if (preg_match('{^https?:}', $queueName)) {
            $queueUrl = $queueName;
        }
        else {
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
        // likely thiqs should be part of the credentials / constructor
        $queueUrl = sprintf('https://sqs.us-east-1.amazonaws.com/%s/%s', $this->awsAccountId, $queueName);

        return $queueUrl;
    }

    /**
     * @param       $queueName
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
}
