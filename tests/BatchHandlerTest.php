<?php

declare(strict_types=1);

namespace Artack\Monolog\JiraHandler;

use Monolog\Logger;

class BatchHandlerTest extends BaseTestCase
{
    public function testHandleBatch(): void
    {
        $formatter = $this->createMock('Monolog\\Formatter\\FormatterInterface');
        $formatter->expects($this->once())
            ->method('formatBatch'); // Each record is formatted

        $handler = $this->getMockForAbstractClass('Artack\\Monolog\\JiraHandler\\BatchHandler', [], '', true, true, true, ['send', 'write']);
        $handler->expects($this->once())
            ->method('send');
        $handler->expects($this->never())
            ->method('write'); // write is for individual records

        $handler->setFormatter($formatter);

        $handler->handleBatch($this->getMultipleRecords());
    }

    public function testHandleBatchNotSendsMailIfMessagesAreBelowLevel(): void
    {
        $records = [
            $this->getRecord(Logger::DEBUG, 'debug message 1'),
            $this->getRecord(Logger::DEBUG, 'debug message 2'),
            $this->getRecord(Logger::INFO, 'information'),
        ];

        $handler = $this->getMockForAbstractClass('Artack\\Monolog\\JiraHandler\\BatchHandler');
        $handler->expects($this->never())
            ->method('send');
        $handler->setLevel(Logger::ERROR);

        $handler->handleBatch($records);
    }

    public function testHandle(): void
    {
        $handler = $this->getMockForAbstractClass('Artack\\Monolog\\JiraHandler\\BatchHandler');
        $handler->setFormatter(new \Monolog\Formatter\LineFormatter());

        $record = $this->getRecord();
        $records = [$record];
        $records[0]['formatted'] = '['.$record['datetime']->format('Y-m-d H:i:s').'] test.WARNING: test [] []'."\n";

        $handler->expects($this->once())
            ->method('send')
            ->with($records[0]['formatted'], $records);

        $handler->handle($record);
    }
}
