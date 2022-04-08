<?php

namespace Symfony\Component\Mailer\Bridge\UniOne\Transport;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Header\Headers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class UniOneApiTransport extends AbstractApiTransport
{
    private const HOST = 'eu1.unione.io';
    private const METHOD_SUPPRESSION_GET = 'transactional/api/v1/suppression/get.json';
    private const METHOD_SUPPRESSION_DELETE = 'transactional/api/v1/suppression/delete.json';
    private const METHOD_EMAIL_SEND = 'transactional/api/v1/email/send.json';
    private const DEFAULT_LOCALE = 'en';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $locale;

    /**
     * @var bool
     */
    private $skipUnsubscribe = false;

    /**
     * @var bool
     */
    private $checkDelitable = false;


    public function __construct(
        string $apiKey,
        string $locale = null,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        $this->apiKey = $apiKey;
        $this->locale = $locale ?? self::DEFAULT_LOCALE;

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf("unione+api://%s", $this->getEndpoint());
    }

    public function setSkipUnsubscribe(bool $value): void
    {
        $this->skipUnsubscribe = $value;
    }

    public function setCheckDelitable(bool $value): void
    {
        $this->checkDelitable = $value;
    }

    protected function request(string $url, array $data, string $method = 'POST'): ResponseInterface
    {
        $jsonData = array_merge([ 'api_key' => $this->apiKey ], $data);

        $response = $this->client->request($method, $url, [ 'json' => $jsonData ]);
        $result = $response->toArray(false);

        if ($response->getStatusCode() !== 200) {
            if ('error' === ($result['status'] ?? false)) {
                throw new HttpTransportException(
                    sprintf('Request error: %s (code %s).', $result['message'], $result['code']),
                    $response
                );
            }

            throw new HttpTransportException(sprintf('Request error (code %s).', $result['code']), $response);
        }

        return $response;
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        if ($this->checkDelitable) {
            try {
                $suppressions = $this->getSuppressions($email, $envelope, true);

                $this->deleteSuppressions(array_keys($suppressions));
            } catch (HttpTransportException $exception) {}
        }

        $url = sprintf('https://%s/%s/%s', $this->getEndpoint(), $this->locale, self::METHOD_EMAIL_SEND);

        $response = $this->request($url, $this->getPayload($email, $envelope));

        $result = $response->toArray(false);
        $sentMessage->setMessageId($result['job_id']);

        return $response;
    }

    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        $recipients = [];
        foreach ($envelope->getRecipients() as $recipient) {
            $recipientPayload = [
                'email' => $recipient->getAddress(),
            ];

            $recipients[] = $recipientPayload;
        }

        return $recipients;
    }

    protected function getSuppressions(Email $email, Envelope $envelope, bool $deletableOnly = false): array
    {
        $result = [];
        $emails = array_column($this->getRecipients($email, $envelope), 'email');
        $url = sprintf('https://%s/%s/%s', $this->getEndpoint(), $this->locale, self::METHOD_SUPPRESSION_GET);

        foreach ($emails as $email) {
            $response = $this->request($url, [ 'email' => $email ])->toArray();

            $suppressions = $response['suppressions'] ?? [];
            $isDeletable = (bool)count(array_filter($suppressions, fn ($suppression) => $suppression['is_deletable'] === true));

            if ($deletableOnly && !$isDeletable) {
                continue;
            }

            $result[$email] = $suppressions;
        }

        return $result;
    }

    protected function deleteSuppressions(string|array ...$emails): void
    {
        foreach ($emails as $email) {
            $this->deleteSuppression($email);
        }
    }

    protected function deleteSuppression(string $email): ResponseInterface
    {
        $url = sprintf('https://%s/%s/%s', $this->getEndpoint(), $this->locale, self::METHOD_SUPPRESSION_DELETE);

        return $this->request($url, [ 'email' => $email ]);
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'message' => [
                'body' => [
                    'html' => $email->getHtmlBody(),
                    'text' => $email->getTextBody(),
                ],
                'subject' => $email->getSubject(),
                'from_email' => $envelope->getSender()->getAddress(),
                'skip_unsubscribe' => (int)$this->skipUnsubscribe,
            ],
        ];

        if (!empty($email->getReplyTo())) {
            $payload['message']['reply_to'] = $email->getReplyTo()[0]->getAddress();
        }

        if ('' !== $envelope->getSender()->getName()) {
            $payload['message']['from_name'] = $envelope->getSender()->getName();
        }

        foreach ($this->getRecipients($email, $envelope) as $recipient) {
            $payload['message']['recipients'][] = ['email' => $recipient['email']];
        }

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $att = [
                'content' => $attachment->bodyToString(),
                'type' => $headers->get('Content-Type')->getBody(),
                'name' => $this->getAttachmentFilename($attachment , $headers),
            ];

            if ('inline' === $disposition) {
                $payload['message']['inline_attachments'][] = $att;
            } else {
                $payload['message']['attachments'][] = $att;
            }
        }
        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }
            $payload['message']['headers'][] = $name . ': ' . $header->toString();
        }

        return $payload;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST) . ($this->port ? ':'. $this->port : '');
    }

    private function getAttachmentFilename(DataPart $attachment, Headers $headers): ?string
    {
        preg_match('/name[^;\n=]*=(([\'"]).*?\2|[^;\n]*)/', $headers->get('Content-Type')->getBodyAsString(), $matches);

        if (isset($matches[0])) {
            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                return str_replace('name=', '', $matches[0]);
            } else {
                return str_replace('name=', '', sprintf('%s.%s', $matches[0] , $attachment->getMediaSubtype()));
            }
        }

        return null;
    }
}
