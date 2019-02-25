<?php

declare(strict_types=1);

namespace Artack\Monolog\JiraHandler;

use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderDefaultsPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\HttpClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Message\Authentication\BasicAuth;
use Monolog\Logger;

class JiraHandler extends BatchHandler
{
    private $hostname;
    private $jql;
    private $hashFieldName;
    private $projectKey;
    private $issueTypeName;
    private $withComments;
    private $counterFieldName;

    private $requestFactory;
    private $urlFactory;
    private $streamFactory;
    private $httpClient;

    private $createdIssueId;

    public function __construct(string $hostname, string $username, string $password, string $jql, string $hashFieldName, string $projectKey, string $issueTypeName, bool $withComments = false, string $counterFieldName = null, HttpClient $httpClient = null, $level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->hostname = $hostname;
        $this->jql = $jql;
        $this->hashFieldName = $hashFieldName;
        $this->projectKey = $projectKey;
        $this->issueTypeName = $issueTypeName;
        $this->withComments = $withComments;
        $this->counterFieldName = $counterFieldName;

        $authentication = new BasicAuth($username, $password);
        $authenticationPlugin = new AuthenticationPlugin($authentication);

        $contentLengthPlugin = new ContentLengthPlugin();
        $headerDefaultsPlugin = new HeaderDefaultsPlugin([
            'Content-Type' => 'application/json',
        ]);

        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->urlFactory = Psr17FactoryDiscovery::findUrlFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        $this->httpClient = new PluginClient(
            $httpClient ?: HttpClientDiscovery::find(),
            [$authenticationPlugin, $headerDefaultsPlugin, $contentLengthPlugin]
        );
    }

    protected function send($content, array $records): void
    {
        $countFieldId = null;
        $highestRecord = $this->getHighestRecord($records);

        $recordForHash = $highestRecord;
        unset($recordForHash['datetime'], $recordForHash['formatted'], $recordForHash['context']);
        $hash = md5(serialize($recordForHash));

        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/customFields', $this->hostname));
        $request = $this->requestFactory->createRequest('GET', $uri);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);
        $hashFieldId = $this->parseCustomFieldId($data, 'values', $this->hashFieldName);

        if ($this->counterFieldName) {
            $countFieldId = $this->parseCustomFieldId($data, 'values', $this->counterFieldName);
        }

        $jql = sprintf('%s AND %s ~ \'%s\'', $this->jql, $this->hashFieldName, $hash);

        $fields = [
            'issuetype',
            'status',
            'summary',
            $hashFieldId,
            $this->counterFieldName,
        ];

        if ($countFieldId) {
            $fields[] = $countFieldId;
        }

        $body = json_encode([
            'jql' => $jql,
            'fields' => $fields,
        ]);

        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/search', $this->hostname));
        $request = $this->requestFactory->createRequest('POST', $uri)->withBody($this->streamFactory->createStream($body));
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($data['total'] > 0) {
            $issueId = $data['issues'][0]['id'];

            if ($this->counterFieldName) {
                $countFieldValue = $data['issues'][0]['fields'][$countFieldId];

                $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue/%d', $this->hostname, $issueId).'?'.http_build_query([
                    'notifyUsers' => false,
                ]));
                $rawBody = [
                    'fields' => [
                        $countFieldId => ++$countFieldValue,
                    ],
                ];
                $body = json_encode($rawBody);
                $request = $this->requestFactory->createRequest('PUT', $uri)->withBody($this->streamFactory->createStream($body));
                $this->httpClient->sendRequest($request);
            }

            if ($this->withComments) {
                $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue/%d/comment', $this->hostname, $issueId));
                $body = json_encode([
                    'body' => $content,
                ]);
                $request = $this->requestFactory->createRequest('POST', $uri)->withBody($this->streamFactory->createStream($body));
                $this->httpClient->sendRequest($request);
            }

            return;
        }

        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue/createmeta', $this->hostname).'?'.http_build_query([
            'projectKeys' => $this->projectKey,
            'expand' => 'projects.issuetypes.fields',
        ]));
        $request = $this->requestFactory->createRequest('GET', $uri);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);

        $projectId = $this->parseProjectId($data);
        $issueType = $this->parseIssueType($data, $this->issueTypeName);
        $issueTypeId = (int) $issueType['id'];

        $body = json_encode([
            'fields' => [
                'project' => [
                    'id' => $projectId,
                ],
                'issuetype' => ['id' => $issueTypeId],
                'summary' => sprintf('%s: %s', $highestRecord['level_name'], $highestRecord['message']),
                'description' => $content,
                $hashFieldId => $hash,
                $countFieldId => 1,
            ],
        ]);
        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue', $this->hostname));
        $request = $this->requestFactory->createRequest('POST', $uri)->withBody($this->streamFactory->createStream($body));
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);
        $this->createdIssueId = $data['id'];
    }

    protected function parseProjectId(array $data): int
    {
        return (int) $data['projects'][0]['id'];
    }

    protected function parseIssueType(array $data, string $issueTypeName): array
    {
        return array_values(array_filter($data['projects'][0]['issuetypes'], function ($data) use ($issueTypeName) {
            return $data['name'] === $issueTypeName;
        }))[0];
    }

    protected function parseCustomFieldId(array $data, string $part, string $fieldName): string
    {
        return array_values(array_filter($data[$part], function ($item) use ($fieldName) {
            return $item['name'] === $fieldName;
        }))[0]['id'];
    }

    public function getCreatedIssueId()
    {
        return $this->createdIssueId;
    }
}
