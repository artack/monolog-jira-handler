<?php

namespace App;

use Buzz\Browser;
use Buzz\Client\Curl;
use Buzz\Client\FileGetContents;
use Buzz\Middleware\BasicAuthMiddleware;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;

class JiraHandler extends AbstractProcessingHandler
{
    private $hostname;
    private $username;
    private $password;
    private $jql;
    private $hashFieldName;
    private $projectKey;
    private $issueTypeName;

    public function __construct(string $hostname, string $username, string $password, string $jql, string $hashFieldName, string $projectKey, string $issueTypeName, $level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->hostname = $hostname;
        $this->username = $username;
        $this->password = $password;
        $this->jql = $jql;
        $this->hashFieldName = $hashFieldName;
        $this->projectKey = $projectKey;
        $this->issueTypeName = $issueTypeName;
    }

    protected function write(array $record): void
    {
        $client = new Curl();
        $browser = new Browser($client, new Psr17Factory());
        $browser->addMiddleware(new BasicAuthMiddleware($this->username, $this->password));

        $recordForHash = $record;
        unset($recordForHash['datetime'], $recordForHash['formatted']);
        $hash = md5(serialize($recordForHash));

        $url = sprintf('https://%s/rest/api/2/search', $this->hostname);

        $jql = sprintf('%s AND %s ~ \'%s\'', $this->jql, $this->hashFieldName, $hash);
        $str = json_encode([
            'jql' => $jql,
            'fields' => [
                'issuetype',
                'status',
                'summary',
                $this->hashFieldName
            ],
        ]);
        $request = new Request('POST', $url, ['Content-Type' => 'application/json'], $str);
        $response = $browser->sendRequest($request);
        $contents = $response->getBody()->getContents();
        $data = json_decode($contents, true);

        if ($data['total'] > 0) {
            // issue already exists > do nothing
            dump("GOT IT ALREADY");

            return;
        }

        $createMetaUrl = sprintf('https://%s/rest/api/2/issue/createmeta', $this->hostname).'?'.http_build_query([
            'projectKeys' => $this->projectKey,
            'expand' => 'projects.issuetypes.fields',
        ]);
        $request = new Request('GET', $createMetaUrl);
        $response = $browser->sendRequest($request);
        $contents = $response->getBody()->getContents();
        $data = json_decode($contents, true);

        $projectId = $data['projects'][0]['id'];

        $issueTypeName = $this->issueTypeName;
        $issueType = array_values(array_filter($data['projects'][0]['issuetypes'], function($data) use($issueTypeName) {
            return $data['name'] === $issueTypeName;
        }))[0];
        $issueTypeId = $issueType['id'];

        $hashFieldName = $this->hashFieldName;
        $hashFieldId = array_keys(array_filter($issueType['fields'], function($data) use($hashFieldName) {
            return $data['name'] === $hashFieldName;
        }))[0];

        $createIssueUrl = sprintf('https://%s/rest/api/2/issue', $this->hostname);
        $createIssueData = [
            'fields' => [
                'project' => [
                    'key' => 'MX',
                ],
                'issuetype' => ['id' => $issueTypeId],
                'summary' => $record['message'],
                'description' => $record['formatted'],
                $hashFieldId => $hash,
            ]
        ];
        $request = new Request('POST', $createIssueUrl, ['Content-Type' => 'application/json'], json_encode($createIssueData));
        $response = $browser->sendRequest($request);
        $contents = $response->getBody()->getContents();
        $data = json_decode($contents, true);
        dump($data);
    }

}
