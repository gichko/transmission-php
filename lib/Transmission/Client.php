<?php
namespace Transmission;

use Buzz\Message\Request;
use Buzz\Message\Response;
use Buzz\Client\Curl;
use Buzz\Client\ClientInterface;
use RuntimeException;
use Throwable;
use stdClass;

/**
 * The Client class is used to make API calls to the Transmission server
 *
 * @author Ramon Kleiss <ramon@cubilon.nl>
 */
class Client
{
    /**
     * @var string
     */
    const DEFAULT_HOST = 'localhost';

    /**
     * @var integer
     */
    const DEFAULT_PORT = 9091;

    /**
     * @var string
     */
    const DEFAULT_PATH = '/transmission/rpc';

    /**
     * @var string
     */
    const DEFAULT_SCHEMA = 'http';

    /**
     * @var string
     */
    const TOKEN_HEADER = 'X-Transmission-Session-Id';

    /**
     * @var string
     */
    protected $schema = self::DEFAULT_SCHEMA;

    /**
     * @var string
     */
    protected $host = self::DEFAULT_HOST;

    /**
     * @var integer
     */
    protected $port = self::DEFAULT_PORT;

    /**
     * @var string
     */
    protected $path = self::DEFAULT_PATH;

    /**
     * @var string
     */
    protected $token;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var string
     */
    protected $auth;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->schema = (string)($config['schema'] ?? self::DEFAULT_SCHEMA);
        $this->host = (string)($config['host'] ?? self::DEFAULT_HOST);
        $this->port = (int)($config['port'] ?? self::DEFAULT_PORT);
        $this->client   = new Curl();
        $this->token    = null;

        if (!empty($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $option => $value) {
                $this->client->setOption($option, $value);
            }
        }
    }

    /**
     * @param string $username
     * @param string $password
     * @return void
     */
    public function authenticate(string $username, string $password): void
    {
        $this->auth = base64_encode($username .':'. $password);
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return stdClass
     * @throws RuntimeException
     */
    public function call(string $method, array $arguments): stdClass
    {
        [$request, $response] = $this->compose($method, $arguments);

        try {
            $this->getClient()->send($request, $response);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not connect to Transmission', 0, $e);
        }

        return $this->validateResponse($response, $method, $arguments);
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return strtr('{schema}://{host}:{port}', [
            '{schema}' => $this->schema,
            '{host}' => $this->getHost(),
            '{port}' => $this->getPort(),
        ]);
    }

    /**
     * @param $host
     * @return void
     */
    public function setHost($host): void
    {
        $this->host = (string)$host;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @param $port
     * @return void
     */
    public function setPort($port): void
    {
        $this->port = (integer)$port;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param $path
     * @return string
     */
    public function setPath($path): string
    {
        return $this->path = (string) $path;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param $token
     * @return void
     */
    public function setToken($token): void
    {
        $this->token = (string)$token;
    }

    /**
     * @return string|null CSRF-token for the Transmission client
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @param ClientInterface $client
     * @return void
     */
    public function setClient(ClientInterface $client): void
    {
        $this->client = $client;
    }

    /**
     * @return ClientInterface Buzz client
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return array
     */
    protected function compose(string $method, array $arguments): array
    {
        $request = new Request('POST', $this->getPath(), $this->getUrl());
        $request->addHeader(sprintf('%s: %s', self::TOKEN_HEADER, $this->getToken()));
        $request->setContent(json_encode(['method'    => $method, 'arguments' => $arguments]));

        if (is_string($this->auth)) {
            $request->addHeader(sprintf('Authorization: Basic %s', $this->auth));
        }

        return [$request, new Response()];
    }

    /**
     * @param Response $response
     * @param string $method
     * @param array $arguments
     * @return stdClass
     * @throws RuntimeException
     */
    protected function validateResponse(Response $response, string $method, array $arguments): stdClass
    {
        switch ($response->getStatusCode()) {
            case 200:
                return json_decode($response->getContent());
            case 409:
                $this->setToken($response->getHeader(self::TOKEN_HEADER));
                return $this->call($method, $arguments);
            case 401:
                throw new RuntimeException('Access to Transmission requires authentication');
            default:
                throw new RuntimeException('Unexpected response received from Transmission');
        }
    }
}
