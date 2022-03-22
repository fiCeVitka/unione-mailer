<?php

namespace Symfony\Component\Mailer\Bridge\UniOne\Transport;

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
    private const METHOD = 'transactional/api/v1/email/send.json';
    private const DEFAULT_LOCALE = 'en';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $locale;

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

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $url = sprintf('https://%s/%s/%s', $this->getEndpoint(), $this->locale, self::METHOD);
        $response = $this->client->request('POST', $url, ['json' => $this->getPayload($email, $envelope)]);

        $result = $response->toArray(false);
        if (200 !== $response->getStatusCode()) {
            if ('error' === ($result['status'] ?? false)) {
                throw new HttpTransportException(
                    sprintf('Unable to send an email: %s (code %s).', $result['message'], $result['code']),
                    $response
                );
            }
            throw new HttpTransportException(sprintf('Unable to send an email (code %s).', $result['code']), $response);
        }
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

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'api_key' => $this->apiKey,
            'message' => [
                'body' => [
                    'html' => $email->getHtmlBody(),
                    'text' => $email->getTextBody(),
                    ],
                'subject' => $email->getSubject(),
                'from_email' => $envelope->getSender()->getAddress(),
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
