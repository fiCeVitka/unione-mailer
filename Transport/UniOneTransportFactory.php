<?php

namespace Symfony\Component\Mailer\Bridge\UniOne\Transport;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;

final class UniOneTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();
        $user = $this->getUser($dsn);
        $locale = $dsn->getOption('locale');
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();
        $port = $dsn->getPort();

        if ($scheme === 'unione+api') {
            return (new UniOneApiTransport($user, $locale, $this->client, $this->dispatcher, $this->logger))
                ->setHost($host)
                ->setPort($port);
        }

        throw new UnsupportedSchemeException($dsn, 'unione', $this->getSupportedSchemes());
    }

    protected function getSupportedSchemes(): array
    {
        return ['unione+api'];
    }
}
