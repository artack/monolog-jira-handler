<?php

declare(strict_types=1);

namespace Artack\Monolog\JiraHandler;

use Monolog\Handler\AbstractProcessingHandler;

/**
 * Base class for all batch handlers.
 *
 * @author ARTACK WebLab GmbH
 */
abstract class BatchHandler extends AbstractProcessingHandler
{
    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        $batchRecords = [];

        foreach ($records as $record) {
            if ($record['level'] < $this->level) {
                continue;
            }
            $batchRecords[] = $this->processRecord($record);
        }

        if (!empty($batchRecords)) {
            $this->send((string) $this->getFormatter()->formatBatch($batchRecords), $batchRecords);
        }
    }

    /**
     * Send a mail with the given content.
     *
     * @param string $content formatted email body to be sent
     * @param array  $records the array of log records that formed this content
     */
    abstract protected function send($content, array $records);

    /**
     * {@inheritdoc}
     */
    protected function write(array $record): void
    {
        $this->send((string) $record['formatted'], [$record]);
    }

    protected function getHighestRecord(array $records)
    {
        $highestRecord = null;
        foreach ($records as $record) {
            if (null === $highestRecord || $highestRecord['level'] < $record['level']) {
                $highestRecord = $record;
            }
        }

        return $highestRecord;
    }
}
